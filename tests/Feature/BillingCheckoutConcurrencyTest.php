<?php

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Models\Account;
use App\Models\User;
use App\Services\Billing\StripeCheckoutGateway;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Checkout;
use Stripe\Checkout\Session;

use function Pest\Laravel\mock;

beforeEach(function () {
    config([
        'billing.plans.standard.prices.monthly' => 'price_standard_monthly',
        'billing.plans.standard.prices.yearly' => 'price_standard_yearly',
        'billing.checkout_reservation_minutes' => 30,
    ]);
});

function openBillingCheckout(
    Account $account,
    string $id = 'cs_checkout',
    string $status = Session::STATUS_OPEN,
): Checkout {
    return new Checkout($account, Session::constructFrom([
        'id' => $id,
        'url' => 'https://checkout.stripe.test/'.$id,
        'status' => $status,
        'expires_at' => now()->addDay()->timestamp,
    ]));
}

function billingPriceFingerprint(string $interval, string $priceId): string
{
    return hash('sha256', 'default|'.$interval.'|'.$priceId);
}

test('an in-flight checkout reservation rejects a concurrent attempt before Stripe is called', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill([
        'billing_checkout_token' => '295581be-58c6-47ee-b09c-b82e3799e05d',
        'billing_checkout_expires_at' => now()->addMinutes(10),
        'billing_checkout_status' => 'preparing',
        'billing_checkout_interval' => 'monthly',
        'billing_checkout_price_fingerprint' => billingPriceFingerprint('monthly', 'price_standard_monthly'),
    ])->save();
    mock(StripeCheckoutGateway::class)->shouldNotReceive('createSubscriptionCheckout');

    expect(fn () => app(StartSubscriptionCheckout::class)->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    ))->toThrow(ValidationException::class);
});

test('an open monthly checkout cannot be silently reused for a yearly request', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $checkout = openBillingCheckout($account, 'cs_monthly');
    $gateway = mock(StripeCheckoutGateway::class);
    $gateway->shouldReceive('createSubscriptionCheckout')->once()->andReturn($checkout);
    $gateway->shouldNotReceive('retrieve');
    $this->app->instance(StripeCheckoutGateway::class, $gateway);
    $start = app(StartSubscriptionCheckout::class);

    $start->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    );

    expect(fn () => $start->handle(
        $account,
        $owner,
        'yearly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    ))->toThrow(ValidationException::class);

    expect($account->refresh()->billing_checkout_interval)->toBe('monthly')
        ->and($account->billing_checkout_session_id)->toBe('cs_monthly');
});

test('a completed checkout intent is persisted and reused instead of creating a second session', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $checkout = openBillingCheckout($account);
    $gateway = mock(StripeCheckoutGateway::class);
    $gateway->shouldReceive('createSubscriptionCheckout')->once()->andReturn($checkout);
    $gateway->shouldReceive('retrieve')->once()->withArgs(
        fn (Account $resolved, string $sessionId): bool => $resolved->is($account) && $sessionId === 'cs_checkout',
    )->andReturn($checkout);
    $this->app->instance(StripeCheckoutGateway::class, $gateway);

    $first = app(StartSubscriptionCheckout::class)->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    );
    $token = $account->refresh()->billing_checkout_token;
    $second = app(StartSubscriptionCheckout::class)->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    );

    expect($first->asStripeCheckoutSession()->id)->toBe('cs_checkout')
        ->and($second->asStripeCheckoutSession()->id)->toBe('cs_checkout')
        ->and($token)->toBeString()
        ->and($account->refresh()->billing_checkout_token)->toBe($token)
        ->and($account->billing_checkout_session_id)->toBe('cs_checkout');
});

test('an uncertain Stripe failure retries with the same durable idempotency token', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $tokens = [];
    $gateway = mock(StripeCheckoutGateway::class);
    $gateway->shouldReceive('createSubscriptionCheckout')
        ->twice()
        ->andReturnUsing(function (Account $resolved, string $token) use (&$tokens, $account): Checkout {
            $tokens[] = $token;

            if (count($tokens) === 1) {
                throw new RuntimeException('uncertain network failure');
            }

            return openBillingCheckout($account, 'cs_recovered');
        });
    $this->app->instance(StripeCheckoutGateway::class, $gateway);
    $start = app(StartSubscriptionCheckout::class);

    expect(fn () => $start->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    ))->toThrow(RuntimeException::class, 'uncertain network failure');

    expect($account->refresh()->billing_checkout_token)->toBeString()
        ->and($account->billing_checkout_expires_at?->isFuture())->toBeFalse();

    $checkout = $start->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    );

    expect($checkout->asStripeCheckoutSession()->id)->toBe('cs_recovered')
        ->and($tokens)->toHaveCount(2)
        ->and($tokens[1])->toBe($tokens[0]);
});

test('a completed checkout with a missed webhook becomes terminal and can never recycle its token', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $open = openBillingCheckout($account, 'cs_completed');
    $complete = openBillingCheckout($account, 'cs_completed', Session::STATUS_COMPLETE);
    $gateway = mock(StripeCheckoutGateway::class);
    $gateway->shouldReceive('createSubscriptionCheckout')->once()->andReturn($open);
    $gateway->shouldReceive('retrieve')->once()->andReturn($complete);
    $this->app->instance(StripeCheckoutGateway::class, $gateway);
    $start = app(StartSubscriptionCheckout::class);

    $start->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    );

    expect(fn () => $start->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    ))->toThrow(ValidationException::class);

    $terminalToken = $account->refresh()->billing_checkout_token;
    expect($account->billing_checkout_status)->toBe('complete')
        ->and($account->billing_checkout_expires_at)->toBeNull();

    $this->travel(2)->days();

    expect(fn () => $start->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    ))->toThrow(ValidationException::class);

    expect($account->refresh()->billing_checkout_token)->toBe($terminalToken)
        ->and($account->billing_checkout_session_id)->toBe('cs_completed')
        ->and($account->billing_checkout_status)->toBe('complete');
});

test('workspace erasure overtaking Stripe session creation expires the session and clears the intent', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $checkout = openBillingCheckout($account, 'cs_erasure_race');
    $gateway = mock(StripeCheckoutGateway::class);
    $gateway->shouldReceive('createSubscriptionCheckout')
        ->once()
        ->andReturnUsing(function () use ($account, $checkout): Checkout {
            Account::query()->whereKey($account->id)->update(['erasure_started_at' => now()]);

            return $checkout;
        });
    $gateway->shouldReceive('expire')->once()->with('cs_erasure_race');
    $this->app->instance(StripeCheckoutGateway::class, $gateway);

    expect(fn () => app(StartSubscriptionCheckout::class)->handle(
        $account,
        $owner,
        'monthly',
        'https://mentrovia.test/success',
        'https://mentrovia.test/cancel',
    ))->toThrow(ValidationException::class);

    expect($account->refresh()->billing_checkout_token)->toBeNull()
        ->and($account->billing_checkout_session_id)->toBeNull()
        ->and($account->billing_checkout_expires_at)->toBeNull();
});
