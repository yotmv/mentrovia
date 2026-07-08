<?php

namespace Database\Factories;

use App\Enums\GenerationBatchStatus;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoGenerationBatch>
 */
class PhotoGenerationBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'status' => GenerationBatchStatus::Pending,
            'user_text' => fake()->sentence(),
            'input_photo_ids' => [],
            'analysis' => null,
            'selected_models' => null,
            'error' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GenerationBatchStatus::Completed,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GenerationBatchStatus::Failed,
            'error' => 'Generation failed.',
        ]);
    }
}
