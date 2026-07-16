<?php

namespace Database\Factories;

use App\Enums\RoadmapExecutionStatus;
use App\Enums\RoadmapPhase;
use App\Enums\RoadmapPriority;
use App\Enums\RoadmapStatus;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapPlanItem>
 */
class RoadmapPlanItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roadmap_plan_id' => RoadmapPlan::factory(),
            'template_key' => fake()->unique()->slug(3),
            'phase' => RoadmapPhase::Foundation,
            'priority' => RoadmapPriority::Required,
            'title' => fake()->sentence(4),
            'why_it_matters' => fake()->paragraph(),
            'reviewer' => null,
            'action_url' => null,
            'action_label' => null,
            'sort_order' => fake()->numberBetween(1, 100),
            'computed_profile_status' => RoadmapStatus::ToDo,
            'execution_status' => RoadmapExecutionStatus::NotStarted,
            'is_active' => true,
            'assigned_user_id' => null,
            'due_on' => today()->addDays(7),
            'notes' => null,
            'completed_at' => null,
            'completed_by_user_id' => null,
            'status_updated_at' => null,
            'status_updated_by_user_id' => null,
        ];
    }
}
