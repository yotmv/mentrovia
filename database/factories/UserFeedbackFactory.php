<?php

namespace Database\Factories;

use App\Enums\FeedbackCategory;
use App\Models\User;
use App\Models\UserFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserFeedback>
 */
class UserFeedbackFactory extends Factory
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
            'category' => fake()->randomElement(FeedbackCategory::cases()),
            'page' => '/dashboard',
            'message' => fake()->paragraph(),
        ];
    }
}
