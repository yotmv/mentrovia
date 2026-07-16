<?php

namespace Database\Factories;

use App\Enums\TaskCategory;
use App\Enums\TaskConfidence;
use App\Enums\TaskFrequency;
use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\RecurringTaskTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessTask>
 */
class BusinessTaskFactory extends Factory
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
            'recurring_task_template_id' => RecurringTaskTemplate::factory(),
            'knowledge_article_id' => null,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(TaskCategory::cases()),
            'frequency' => fake()->randomElement(TaskFrequency::cases()),
            'due_rule' => ['type' => 'end_of_period'],
            'due_on' => now()->addWeek()->toDateString(),
            'confidence' => TaskConfidence::High,
            'requires_professional_review' => false,
            'is_active' => true,
            'retired_at' => null,
            'completed_at' => null,
            'notes' => null,
        ];
    }
}
