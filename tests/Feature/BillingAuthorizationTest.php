<?php

use App\Actions\Billing\OpenBillingPortal;
use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Laravel\Cashier\Checkout;
use Stripe\Checkout\Session;

use function Pest\Laravel\mock;

function passwordConfirmedSession(): array
{
    return ['auth.password_confirmed_at' => now()->timestamp];
}

test('billing mutations require authentication and recent password confirmation', function () {
    $this->post(route('billing.checkout'), ['interval' => 'monthly'])
        ->assertRedirect(route('login'));

    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('billing.checkout'), ['interval' => 'monthly'])
        ->assertRedirect(route('password.confirm'));
});

test('only the current workspace owner may start checkout', function (AccountRole $role) {
    $workspaceOwner = User::factory()->create();
    $actor = User::factory()->create();
    $account = $workspaceOwner->currentAccount;
    $account->members()->attach($actor, ['role' => $role]);
    $actor->forceFill(['current_account_id' => $account->id])->save();

    mock(StartSubscriptionCheckout::class)->shouldNotReceive('handle');

    $this->actingAs($actor)
        ->withSession(passwordConfirmedSession())
        ->post(route('billing.checkout'), ['interval' => 'monthly'])
        ->assertForbidden();
})->with([AccountRole::Admin, AccountRole::Member]);

test('checkout accepts only a server mapped interval and never a raw Stripe price', function () {
    $owner = User::factory()->create();
    mock(StartSubscriptionCheckout::class)->shouldNotReceive('handle');

    $this->actingAs($owner)
        ->withSession(passwordConfirmedSession())
        ->from(route('billing.edit'))
        ->post(route('billing.checkout'), [
            'interval' => 'price_attacker_controlled',
            'price' => 'price_attacker_controlled',
        ])
        ->assertRedirect(route('billing.edit'))
        ->assertSessionHasErrors('interval');
});

test('the owner checkout action receives only the resolved current account and interval', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $checkout = new Checkout($account, Session::constructFrom([
        'id' => 'cs_test',
        'url' => 'https://checkout.stripe.test/session',
    ]));
    $action = mock(StartSubscriptionCheckout::class);
    $action->shouldReceive('handle')
        ->once()
        ->withArgs(function (
            Account $resolved,
            User $actor,
            string $interval,
            string $successUrl,
            string $cancelUrl,
        ) use ($account, $owner): bool {
            return $resolved->is($account)
                && $actor->is($owner)
                && $interval === 'yearly'
                && str_starts_with($successUrl, route('billing.edit'))
                && str_starts_with($cancelUrl, route('billing.edit'))
                && str_contains($successUrl, 'billing=pending')
                && str_contains($cancelUrl, 'billing=canceled');
        })
        ->andReturn($checkout);

    $this->actingAs($owner)
        ->withSession(passwordConfirmedSession())
        ->post(route('billing.checkout'), ['interval' => 'yearly'])
        ->assertRedirect('https://checkout.stripe.test/session')
        ->assertStatus(303);

    expect($account->entitlement->refresh()->status)->toBe(AccountEntitlementStatus::Trialing);
});

test('only the owner can open the billing portal and return URLs are server controlled', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $portal = mock(OpenBillingPortal::class);
    $portal->shouldReceive('handle')
        ->once()
        ->withArgs(fn (Account $resolved, User $actor, string $returnUrl): bool => $resolved->is($account)
            && $actor->is($owner)
            && $returnUrl === route('billing.edit'))
        ->andReturn(new RedirectResponse('https://billing.stripe.test/portal'));

    $this->actingAs($owner)
        ->withSession(passwordConfirmedSession())
        ->post(route('billing.portal'), ['return_url' => 'https://attacker.test'])
        ->assertRedirect('https://billing.stripe.test/portal');
});

test('billing mutations fail closed after workspace erasure starts', function () {
    $owner = User::factory()->create();
    $owner->currentAccount->forceFill(['erasure_started_at' => now()])->save();
    mock(StartSubscriptionCheckout::class)->shouldNotReceive('handle');

    $this->actingAs($owner)
        ->withSession(passwordConfirmedSession())
        ->post(route('billing.checkout'), ['interval' => 'monthly'])
        ->assertForbidden();
});
