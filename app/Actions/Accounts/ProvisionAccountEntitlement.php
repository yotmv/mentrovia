<?php

namespace App\Actions\Accounts;

use App\Enums\AccountEntitlementStatus;
use App\Models\Account;

class ProvisionAccountEntitlement
{
    public function handle(Account $account): void
    {
        $trialEndsAt = now()->addDays((int) config('billing.trial_days', 14));
        $account->entitlement()->firstOrCreate([], [
            'plan' => 'standard',
            'status' => AccountEntitlementStatus::Trialing,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }
}
