<?php

use App\Actions\Accounts\CreatePersonalAccount;
use App\Enums\AccountCapability;
use App\Enums\AccountEntitlementStatus;
use App\Models\User;
use App\Services\Accounts\AccountEntitlementGate;
use App\Services\Billing\BillingPriceCatalog;
use Illuminate\Support\Carbon;

test('new workspaces receive one centralized standard trial with every current capability', function () {
    Carbon::setTestNow('2026-07-15 12:00:00');
    $user = User::factory()->create();
    $account = $user->currentAccount;

    expect($account->entitlement->plan)->toBe('standard')
        ->and($account->entitlement->status)->toBe(AccountEntitlementStatus::Trialing)
        ->and($account->entitlement->trial_ends_at?->toDateTimeString())->toBe('2026-07-29 12:00:00')
        ->and($account->trial_ends_at)->toBeNull();

    foreach (AccountCapability::cases() as $capability) {
        expect(app(AccountEntitlementGate::class)->allows($account, $capability))->toBeTrue();
    }

    app(CreatePersonalAccount::class)->handle($user);

    expect($account->entitlement()->count())->toBe(1)
        ->and($account->refresh()->trial_ends_at)->toBeNull();
});

test('grandfathered beta workspaces remain active and are not converted by idempotent provisioning', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $account->forceFill(['trial_ends_at' => null])->save();
    $account->entitlement()->update([
        'plan' => 'beta',
        'status' => AccountEntitlementStatus::Active,
        'trial_ends_at' => null,
    ]);

    app(CreatePersonalAccount::class)->handle($user);

    expect($account->refresh()->trial_ends_at)->toBeNull()
        ->and($account->entitlement->refresh()->plan)->toBe('beta')
        ->and($account->entitlement->status)->toBe(AccountEntitlementStatus::Active);
});

test('the server-side price catalog maps only configured intervals and prices', function () {
    config([
        'billing.plans.standard.prices.monthly' => 'price_monthly',
        'billing.plans.standard.prices.yearly' => 'price_yearly',
    ]);
    $catalog = app(BillingPriceCatalog::class);

    expect($catalog->priceForInterval('monthly'))->toBe('price_monthly')
        ->and($catalog->priceForInterval('yearly'))->toBe('price_yearly')
        ->and($catalog->planForPrice('price_monthly'))->toBe('standard')
        ->and($catalog->planForPrice('price_attacker'))->toBeNull()
        ->and(fn () => $catalog->priceForInterval('price_attacker'))->toThrow(InvalidArgumentException::class);

    config(['billing.plans.standard.prices.monthly' => null]);

    expect(fn () => $catalog->priceForInterval('monthly'))->toThrow(RuntimeException::class);
});
