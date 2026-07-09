<?php

namespace Database\Factories;

use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Models\ValidationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationRun>
 */
class ValidationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_article_id' => KnowledgeArticle::factory(),
            'user_id' => User::factory(),
            'business_id' => Business::factory(),
            'normalized_request' => [
                'article_slug' => fake()->slug(),
                'question' => fake()->sentence(),
                'risk_level' => 'high',
            ],
            'context_snapshot' => [
                'state' => 'TX',
                'legal_structure' => 'sole_proprietor',
            ],
            'status' => ValidationRunStatus::Pending,
            'aggregate_decision' => null,
            'final_model_role' => null,
            'final_provider' => null,
            'final_model' => null,
            'confidence' => null,
            'flags' => [],
            'concerns' => [],
            'raw_response' => null,
            'metadata' => [],
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn (): array => [
            'business_id' => $business->id,
            'user_id' => $business->user_id,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ValidationRunStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(ValidationDecision $decision = ValidationDecision::ApprovedCurrent): static
    {
        return $this->state(fn (): array => [
            'status' => ValidationRunStatus::Completed,
            'aggregate_decision' => $decision,
            'final_model_role' => TextGenerationRole::FinalJudge,
            'final_provider' => 'openrouter',
            'final_model' => 'openai/gpt-4.1-mini',
            'confidence' => 86,
            'raw_response' => [
                'decision' => $decision->value,
                'summary' => fake()->sentence(),
            ],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ValidationRunStatus::Failed,
            'aggregate_decision' => ValidationDecision::AdminReviewRequired,
            'flags' => ['pipeline_error'],
            'concerns' => ['Validation pipeline failed before a reliable decision was reached.'],
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }
}
