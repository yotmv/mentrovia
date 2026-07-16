<?php

namespace Database\Factories;

use App\Enums\GenerationBatchStatus;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
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
            'account_id' => fn (array $attributes): int => Project::query()->whereKey($attributes['project_id'])->firstOrFail()->account_id,
            'user_id' => fn (array $attributes): int => Project::query()->whereKey($attributes['project_id'])->firstOrFail()->user_id,
            'status' => GenerationBatchStatus::Pending,
            'analysis_enqueued_at' => null,
            'analysis_state' => 'pending',
            'analysis_operation_uuid' => null,
            'analysis_execution_token' => null,
            'analysis_fence' => 0,
            'analysis_claim_expires_at' => null,
            'analysis_provider_started_at' => null,
            'analysis_failure_code' => null,
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
