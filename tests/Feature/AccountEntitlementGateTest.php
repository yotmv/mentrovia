<?php

use App\Enums\AccountCapability;
use App\Enums\AccountEntitlementStatus;
use App\Models\Account;
use App\Services\Accounts\AccountEntitlementGate;

test('account entitlement status controls every beta capability', function (
    AccountEntitlementStatus $status,
    ?string $trialEnd,
    bool $expected,
) {
    $account = Account::factory()->create();
    $account->entitlement()->create([
        'plan' => 'beta',
        'status' => $status,
        'trial_ends_at' => $trialEnd,
    ]);

    $gate = app(AccountEntitlementGate::class);

    foreach (AccountCapability::cases() as $capability) {
        expect($gate->allows($account, $capability))->toBe($expected);
    }
})->with([
    'active' => [AccountEntitlementStatus::Active, null, true],
    'future trial' => [AccountEntitlementStatus::Trialing, '+1 day', true],
    'expired trial' => [AccountEntitlementStatus::Trialing, '-1 second', false],
    'suspended' => [AccountEntitlementStatus::Suspended, null, false],
    'canceled' => [AccountEntitlementStatus::Canceled, null, false],
]);

test('an account without an entitlement is denied', function () {
    $account = Account::factory()->create();

    expect(app(AccountEntitlementGate::class)->allows($account, AccountCapability::Workspace))->toBeFalse();
});

test('an unknown active plan fails closed for every capability', function () {
    $account = Account::factory()->create();
    $account->entitlement()->create([
        'plan' => 'future-unconfigured-plan',
        'status' => AccountEntitlementStatus::Active,
    ]);

    foreach (AccountCapability::cases() as $capability) {
        expect(app(AccountEntitlementGate::class)->allows($account, $capability))->toBeFalse();
    }
});
