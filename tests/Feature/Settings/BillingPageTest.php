<?php

use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Models\User;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    config([
        'billing.plans.standard.prices.monthly' => 'price_monthly_private_test_value',
        'billing.plans.standard.prices.yearly' => 'price_yearly_private_test_value',
    ]);
});

test('the owner can view provider neutral billing state and server mapped checkout choices', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Standard')
        ->assertSee('Trialing')
        ->assertSee('Monthly')
        ->assertSee('Yearly')
        ->assertSee('value="monthly"', false)
        ->assertSee('value="yearly"', false)
        ->assertDontSee('price_monthly_private_test_value')
        ->assertDontSee('price_yearly_private_test_value')
        ->assertDontSee('Open Stripe billing portal');
});

test('billing page and navigation are owner only', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();

    $this->get(route('billing.edit'))->assertRedirect(route('login'));

    $this->actingAs($member)
        ->get(route('billing.edit'))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Billing');

    $this->actingAs($owner)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Billing');
});

test('grandfathered beta access does not offer a paid checkout', function () {
    $owner = User::factory()->create();
    $owner->currentAccount->entitlement()->update([
        'plan' => 'beta',
        'status' => AccountEntitlementStatus::Active,
        'trial_ends_at' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Grandfathered beta')
        ->assertSee('No subscription is required')
        ->assertDontSee('Choose a billing interval')
        ->assertDontSee('Open Stripe billing portal');
});

test('provider neutral access states are presented without inventing Stripe state', function (AccountEntitlementStatus $status, string $label) {
    $owner = User::factory()->create();
    $owner->currentAccount->entitlement()->update([
        'plan' => 'standard',
        'status' => $status,
        'trial_ends_at' => $status === AccountEntitlementStatus::Trialing ? now()->addDays(14) : null,
    ]);

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee($label)
        ->assertDontSee('Stripe subscription');
})->with([
    'trialing' => [AccountEntitlementStatus::Trialing, 'Trialing'],
    'active' => [AccountEntitlementStatus::Active, 'Active'],
    'suspended' => [AccountEntitlementStatus::Suspended, 'Suspended'],
    'canceled' => [AccountEntitlementStatus::Canceled, 'Canceled'],
]);

test('a local Stripe customer gets one portal action and no checkout price data', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_local_test'])->save();
    Subscription::query()->create([
        'account_id' => $account->id,
        'type' => 'default',
        'stripe_id' => 'sub_local_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_subscription_private_value',
        'quantity' => 1,
    ]);
    $account->entitlement()->update([
        'plan' => 'standard',
        'status' => AccountEntitlementStatus::Active,
        'trial_ends_at' => null,
    ]);

    $response = $this->actingAs($owner)->get(route('billing.edit'));

    $response->assertOk()
        ->assertSee('Stripe subscription')
        ->assertSee('Open Stripe billing portal')
        ->assertSee(route('billing.portal'), false)
        ->assertDontSee('Choose a billing interval')
        ->assertDontSee('price_subscription_private_value')
        ->assertDontSee('price_monthly_private_test_value');

    expect(substr_count($response->getContent(), route('billing.portal')))->toBe(1);
});

test('a Stripe customer without a current subscription can use both the portal and checkout', function () {
    $owner = User::factory()->create();
    $owner->currentAccount->forceFill(['stripe_id' => 'cus_customer_only'])->save();

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Open Stripe billing portal')
        ->assertSee('Choose a billing interval')
        ->assertSee('value="monthly"', false)
        ->assertSee('value="yearly"', false)
        ->assertDontSee('price_monthly_private_test_value');
});

test('an ended subscription does not prevent a safe resubscription', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_ended_subscription'])->save();
    Subscription::query()->create([
        'account_id' => $account->id,
        'type' => 'default',
        'stripe_id' => 'sub_ended_subscription',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_historical_private_value',
        'quantity' => 1,
        'ends_at' => now()->subMinute(),
    ]);
    $account->entitlement()->update([
        'plan' => 'standard',
        'status' => AccountEntitlementStatus::Canceled,
        'trial_ends_at' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Canceled')
        ->assertSee('Open Stripe billing portal')
        ->assertSee('Choose a billing interval')
        ->assertDontSee('price_historical_private_value');
});

test('live and terminal checkout fences stay visibly pending even when the portal exists', function (string $status, ?string $expiresAt, string $message) {
    $owner = User::factory()->create();
    $owner->currentAccount->forceFill([
        'stripe_id' => 'cus_checkout_fence_'.$status,
        'billing_checkout_token' => 'a1232504-ecf5-484f-8527-76510ca367e9',
        'billing_checkout_session_id' => 'cs_checkout_fence_'.$status,
        'billing_checkout_expires_at' => $expiresAt,
        'billing_checkout_status' => $status,
        'billing_checkout_interval' => 'monthly',
        'billing_checkout_price_fingerprint' => hash('sha256', 'default|monthly|price_monthly_private_test_value'),
    ])->save();

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Checkout confirmation is still pending')
        ->assertSee($message)
        ->assertSee('Open Stripe billing portal')
        ->assertDontSee('Choose a billing interval');
})->with([
    'preparing' => ['preparing', '+10 minutes', 'still open or being prepared'],
    'open' => ['open', '+10 minutes', 'still open or being prepared'],
    'complete' => ['complete', null, 'Stripe reports that checkout completed'],
]);

test('an expired retryable checkout exposes only its originally bound interval', function () {
    $owner = User::factory()->create();
    $owner->currentAccount->forceFill([
        'stripe_id' => 'cus_retryable_checkout',
        'billing_checkout_token' => '48e7ed1d-dde4-4ca9-a4d9-feec2ec7f108',
        'billing_checkout_session_id' => null,
        'billing_checkout_expires_at' => now()->subMinute(),
        'billing_checkout_status' => 'preparing',
        'billing_checkout_interval' => 'monthly',
        'billing_checkout_price_fingerprint' => hash('sha256', 'default|monthly|price_monthly_private_test_value'),
    ])->save();

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Open Stripe billing portal')
        ->assertSee('Choose a billing interval')
        ->assertSee('value="monthly"', false)
        ->assertDontSee('value="yearly"', false)
        ->assertDontSee('Checkout confirmation is still pending');
});

test('active Standard access without Stripe evidence fails visibly to support', function () {
    $owner = User::factory()->create();
    $owner->currentAccount->entitlement()->update([
        'plan' => 'standard',
        'status' => AccountEntitlementStatus::Active,
        'trial_ends_at' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('billing.edit'))
        ->assertOk()
        ->assertSee('Billing needs support')
        ->assertSee('active access record and local Stripe billing evidence do not agree')
        ->assertDontSee('Choose a billing interval')
        ->assertDontSee('Open Stripe billing portal');
});

test('billing return states explain pending confirmation and canceled checkout', function (string $state, string $message) {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('billing.edit', ['billing' => $state]))
        ->assertOk()
        ->assertSee($message);
})->with([
    'pending' => ['pending', 'Billing confirmation is pending'],
    'canceled' => ['canceled', 'Checkout was canceled'],
]);
