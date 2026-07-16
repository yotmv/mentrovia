<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\RoadmapPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoadmapPlan>
 */
class RoadmapPlanFactory extends Factory
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
            'fingerprint' => hash('sha256', fake()->uuid()),
            'revision' => 1,
            'last_synced_at' => now(),
        ];
    }
}
