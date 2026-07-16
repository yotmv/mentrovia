<?php

namespace Database\Factories;

use App\Enums\AccountEntitlementStatus;
use App\Models\Account;
use App\Models\AccountEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountEntitlement>
 */
class AccountEntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'plan' => 'beta',
            'status' => AccountEntitlementStatus::Active,
            'trial_ends_at' => null,
        ];
    }
}
