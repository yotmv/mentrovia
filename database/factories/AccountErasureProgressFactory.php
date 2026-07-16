<?php

namespace Database\Factories;

use App\Models\AccountErasureProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountErasureProgress>
 */
class AccountErasureProgressFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phase' => 'scan_batches',
            'cursor' => 0,
            'revision' => 0,
            'enqueued_at' => null,
        ];
    }
}
