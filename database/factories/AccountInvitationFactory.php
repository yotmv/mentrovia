<?php

namespace Database\Factories;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountInvitation>
 */
class AccountInvitationFactory extends Factory
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
            'invited_by_user_id' => User::factory(),
            'accepted_by_user_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'role' => AccountRole::Member,
            'token_hash' => hash('sha256', fake()->sha256()),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }
}
