<?php

namespace Database\Factories;

use App\Models\RoadmapItemEvidence;
use App\Models\RoadmapPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapItemEvidence>
 */
class RoadmapItemEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roadmap_plan_item_id' => RoadmapPlanItem::factory(),
            'label' => fake()->sentence(3),
            'reference_url' => 'https://'.fake()->domainName().'/'.fake()->slug(),
            'notes' => fake()->optional()->sentence(),
            'added_by_user_id' => null,
        ];
    }
}
