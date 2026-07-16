<?php

namespace Database\Factories;

use App\Models\RoadmapItemDependency;
use App\Models\RoadmapPlanItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapItemDependency>
 */
class RoadmapItemDependencyFactory extends Factory
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
            'roadmap_plan_id' => fn (array $attributes): int => RoadmapPlanItem::query()
                ->findOrFail((int) $attributes['roadmap_plan_item_id'])
                ->roadmap_plan_id,
            'depends_on_roadmap_plan_item_id' => function (array $attributes): int {
                $item = RoadmapPlanItem::query()->findOrFail((int) $attributes['roadmap_plan_item_id']);

                return RoadmapPlanItem::factory()->create([
                    'roadmap_plan_id' => $item->roadmap_plan_id,
                ])->id;
            },
        ];
    }
}
