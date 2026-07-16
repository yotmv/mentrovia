<?php

namespace App\Services\Accounts;

use App\Enums\AccountCapability;
use App\Enums\AccountEntitlementStatus;
use App\Models\Account;

class AccountEntitlementGate
{
    /** @var array<string, list<AccountCapability>> */
    private const PlanCapabilities = [
        'beta' => [
            AccountCapability::Workspace,
            AccountCapability::Project,
            AccountCapability::Photo,
            AccountCapability::HostedAi,
        ],
        'standard' => [
            AccountCapability::Workspace,
            AccountCapability::Project,
            AccountCapability::Photo,
            AccountCapability::HostedAi,
        ],
    ];

    public function allows(Account $account, AccountCapability $capability): bool
    {
        if ($account->erasure_started_at !== null) {
            return false;
        }

        $entitlement = $account->entitlement;

        if ($entitlement === null
            || ! array_key_exists($entitlement->plan, self::PlanCapabilities)
            || ! in_array($capability, self::PlanCapabilities[$entitlement->plan], true)) {
            return false;
        }

        return match ($entitlement->status) {
            AccountEntitlementStatus::Active => true,
            AccountEntitlementStatus::Trialing => $entitlement->trial_ends_at?->isFuture() === true,
            AccountEntitlementStatus::Suspended, AccountEntitlementStatus::Canceled => false,
        };
    }
}
