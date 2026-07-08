<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfile>
 */
class BusinessProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'question_key' => fake()->unique()->slug(3),
            'answer_value' => fake()->sentence(),
            'confidence' => fake()->randomElement(['high', 'medium', 'low']),
        ];
    }
}
