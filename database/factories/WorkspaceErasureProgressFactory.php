<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use App\Models\WorkspaceErasureProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceErasureProgress>
 */
class WorkspaceErasureProgressFactory extends Factory
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
            'requested_by_user_id' => User::factory(),
            'phase' => 'drain_work',
            'checkpoint' => 'primary',
            'cursor' => 0,
            'revision' => 0,
            'attempts' => 0,
            'dispatch_token' => null,
            'enqueued_at' => null,
            'claimed_at' => null,
            'claim_expires_at' => null,
            'last_progress_at' => null,
            'storage_verified_at' => null,
            'completed_at' => null,
            'last_error_code' => null,
        ];
    }
}
