<?php

use App\Actions\Billing\ProjectStripeSubscription;
use App\Enums\AccountEntitlementStatus;
use App\Models\Account;
use App\Models\StripeWebhookProjection;
use App\Models\User;
use Laravel\Cashier\Subscription;
use Stripe\Subscription as StripeSubscription;

function createBillingSubscription(
    Account $account,
    string $status = StripeSubscription::STATUS_ACTIVE,
    string $price = 'price_standard_monthly',
    ?DateTimeInterface $trialEndsAt = null,
    ?DateTimeInterface $endsAt = null,
): Subscription {
    $subscription = $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.fake()->unique()->numerify('########'),
        'stripe_status' => $status,
        'stripe_price' => $price,
        'quantity' => 1,
        'trial_ends_at' => $trialEndsAt,
        'ends_at' => $endsAt,
    ]);
    $subscription->items()->create([
        'stripe_id' => 'si_'.fake()->unique()->numerify('########'),
        'stripe_product' => 'prod_standard',
        'stripe_price' => $price,
        'quantity' => 1,
    ]);

    return $subscription;
}

function projectBillingEvent(Account $account, string $eventId, string $eventType, int $created): void
{
    $projector = app(ProjectStripeSubscription::class);

    expect($projector->admit($eventId, $eventType, $created, $account->id))->toBeTrue();
    $projector->complete($eventId);
}

function stripeSignature(string $payload, string $secret, int $timestamp): string
{
    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
}

beforeEach(function () {
    config([
        'billing.plans.standard.prices.monthly' => 'price_standard_monthly',
        'billing.plans.standard.prices.yearly' => 'price_standard_yearly',
        'cashier.webhook.secret' => 'whsec_test_secret',
    ]);
});

test('recognized active and trialing subscriptions project into the provider neutral entitlement', function () {
    $activeOwner = User::factory()->create();
    $active = $activeOwner->currentAccount;
    createBillingSubscription($active);

    projectBillingEvent($active, 'evt_active', 'customer.subscription.created', 100);

    expect($active->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Active)
        ->and($active->entitlement->trial_ends_at)->toBeNull();

    $trialOwner = User::factory()->create();
    $trial = $trialOwner->currentAccount;
    createBillingSubscription(
        $trial,
        StripeSubscription::STATUS_TRIALING,
        trialEndsAt: now()->addDays(5),
    );

    projectBillingEvent($trial, 'evt_trial', 'customer.subscription.created', 101);

    expect($trial->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Trialing)
        ->and($trial->entitlement->trial_ends_at?->isFuture())->toBeTrue();
});

test('non active Stripe states fail closed', function (string $state) {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    createBillingSubscription($account, $state);

    projectBillingEvent($account, 'evt_'.$state, 'customer.subscription.updated', 200);

    expect($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Suspended);
})->with([
    StripeSubscription::STATUS_PAST_DUE,
    StripeSubscription::STATUS_INCOMPLETE,
    StripeSubscription::STATUS_UNPAID,
]);

test('mixed configured prices suspend even when each individual price is supported', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $subscription = createBillingSubscription($account);
    $subscription->items()->create([
        'stripe_id' => 'si_yearly',
        'stripe_product' => 'prod_standard',
        'stripe_price' => 'price_standard_yearly',
        'quantity' => 1,
    ]);

    projectBillingEvent($account, 'evt_mixed', 'customer.subscription.updated', 300);

    expect($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Suspended);
});

test('a signed incomplete expired update succeeds and suspends after Cashier removes the local subscription', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill([
        'stripe_id' => 'cus_incomplete_expired',
        'billing_checkout_token' => 'a5ae837b-cdf1-48bb-a12b-d675abf9db6e',
        'billing_checkout_session_id' => 'cs_incomplete_expired',
        'billing_checkout_expires_at' => now()->addHour(),
    ])->save();
    $subscription = createBillingSubscription($account, StripeSubscription::STATUS_INCOMPLETE);
    $payload = json_encode([
        'id' => 'evt_incomplete_expired',
        'type' => 'customer.subscription.updated',
        'created' => 210,
        'data' => ['object' => [
            'id' => $subscription->stripe_id,
            'customer' => 'cus_incomplete_expired',
            'status' => StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
            'cancel_at_period_end' => false,
            'items' => ['data' => []],
        ]],
    ], JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;

    $this->call('POST', route('cashier.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_test_secret', $timestamp),
    ], $payload)->assertSuccessful();

    expect(Subscription::query()->whereKey($subscription->id)->exists())->toBeFalse()
        ->and($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Suspended)
        ->and($account->refresh()->billing_checkout_token)->toBeNull()
        ->and($account->billing_checkout_session_id)->toBeNull()
        ->and(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_incomplete_expired')->value('outcome'))
        ->toBe('subscription_incomplete_expired');
});

test('an unknown price fails closed', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    createBillingSubscription($account, price: 'price_unknown');

    projectBillingEvent($account, 'evt_unknown_price', 'customer.subscription.updated', 250);

    expect($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Suspended);
});

test('an ended standard subscription cancels while beta without a subscription remains grandfathered', function () {
    $standardOwner = User::factory()->create();
    $standard = $standardOwner->currentAccount;
    createBillingSubscription(
        $standard,
        StripeSubscription::STATUS_CANCELED,
        endsAt: now()->subMinute(),
    );

    projectBillingEvent($standard, 'evt_ended', 'customer.subscription.deleted', 400);

    expect($standard->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Canceled);

    $betaOwner = User::factory()->create();
    $beta = $betaOwner->currentAccount;
    $beta->entitlement()->update([
        'plan' => 'beta',
        'status' => AccountEntitlementStatus::Active,
        'trial_ends_at' => null,
    ]);

    projectBillingEvent($beta, 'evt_beta_delete', 'customer.deleted', 401);

    expect($beta->entitlement->refresh()->plan)->toBe('beta')
        ->and($beta->entitlement->status)->toBe(AccountEntitlementStatus::Active);
});

test('projection replay is idempotent and an older event is rejected before Cashier can regress state', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_ordered'])->save();
    $subscription = createBillingSubscription($account);
    projectBillingEvent($account, 'evt_newer', 'customer.subscription.updated', 600);

    $payload = json_encode([
        'id' => 'evt_older',
        'type' => 'customer.subscription.updated',
        'created' => 500,
        'data' => ['object' => [
            'id' => $subscription->stripe_id,
            'customer' => 'cus_ordered',
            'status' => StripeSubscription::STATUS_PAST_DUE,
            'cancel_at_period_end' => false,
            'items' => ['data' => [[
                'id' => $subscription->items->sole()->stripe_id,
                'price' => ['id' => 'price_standard_monthly', 'product' => 'prod_standard'],
                'quantity' => 1,
            ]]],
        ]],
    ], JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;

    $this->call('POST', route('cashier.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_test_secret', $timestamp),
    ], $payload)->assertSuccessful();

    expect($subscription->refresh()->stripe_status)->toBe(StripeSubscription::STATUS_ACTIVE)
        ->and($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Active)
        ->and(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_older')->value('outcome'))->toBe('stale_ignored')
        ->and(app(ProjectStripeSubscription::class)->admit('evt_newer', 'customer.subscription.updated', 600, $account->id))->toBeFalse()
        ->and(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_newer')->count())->toBe(1);
});

test('equal timestamp events use a stable event id watermark regardless of delivery order', function (array $eventIds) {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    createBillingSubscription($account);

    foreach ($eventIds as $eventId) {
        $projector = app(ProjectStripeSubscription::class);

        if ($projector->admit($eventId, 'customer.subscription.updated', 650, $account->id)) {
            $projector->complete($eventId);
        }
    }

    expect(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_equal_z')->value('outcome'))
        ->toBe('standard_active')
        ->and(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_equal_a')->value('outcome'))
        ->toBe($eventIds[0] === 'evt_equal_z' ? 'stale_ignored' : 'standard_active');
})->with([
    'higher watermark first' => [['evt_equal_z', 'evt_equal_a']],
    'higher watermark last' => [['evt_equal_a', 'evt_equal_z']],
]);

test('signed customer deletion correlates before Cashier clears the customer and rejects unsigned requests', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_delete_me'])->save();
    $subscription = createBillingSubscription($account);
    $payload = json_encode([
        'id' => 'evt_customer_deleted',
        'type' => 'customer.deleted',
        'created' => 700,
        'data' => ['object' => ['id' => 'cus_delete_me']],
    ], JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;

    $this->postJson(route('cashier.webhook'), json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
        ->assertForbidden();

    $this->call('POST', route('cashier.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_test_secret', $timestamp),
    ], $payload)->assertSuccessful();

    expect($account->refresh()->stripe_id)->toBeNull()
        ->and($subscription->refresh()->ended())->toBeTrue()
        ->and($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Canceled)
        ->and(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_customer_deleted')->value('account_id'))->toBe($account->id);

    $this->call('POST', route('cashier.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_test_secret', $timestamp),
    ], $payload)->assertSuccessful();

    expect(StripeWebhookProjection::query()->where('stripe_event_id', 'evt_customer_deleted')->count())->toBe(1);
});

test('the webhook lock lease must exceed the declared processing budget', function () {
    config([
        'billing.webhook_processing_budget_seconds' => 300,
        'billing.webhook_lock_seconds' => 300,
    ]);
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_invalid_lock'])->save();
    $payload = json_encode([
        'id' => 'evt_invalid_lock',
        'type' => 'customer.deleted',
        'created' => 800,
        'data' => ['object' => ['id' => 'cus_invalid_lock']],
    ], JSON_THROW_ON_ERROR);
    $timestamp = now()->timestamp;

    $this->withoutExceptionHandling();

    expect(fn () => $this->call('POST', route('cashier.webhook'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => stripeSignature($payload, 'whsec_test_secret', $timestamp),
    ], $payload))->toThrow(LogicException::class, 'lock must exceed its processing budget');
});
