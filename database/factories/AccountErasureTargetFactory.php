<?php

namespace Database\Factories;

use App\Models\AccountErasureTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountErasureTarget>
 */
class AccountErasureTargetFactory extends Factory
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
            'resource_type' => 'photo_generation_batch',
            'resource_id' => fake()->numberBetween(1, 100000),
        ];
    }
}
