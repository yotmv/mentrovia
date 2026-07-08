<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\TaskCompletion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskCompletion>
 */
class TaskCompletionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_task_id' => BusinessTask::factory(),
            'business_id' => Business::factory(),
            'completed_for' => now()->toDateString(),
            'completed_at' => now(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
