<?php

namespace Database\Factories;

use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Models\ValidationRun;
use App\Models\ValidationVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValidationVote>
 */
class ValidationVoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'validation_run_id' => ValidationRun::factory(),
            'model_role' => fake()->randomElement([
                TextGenerationRole::ValidatorFactual,
                TextGenerationRole::ValidatorContradiction,
                TextGenerationRole::ValidatorUserFit,
            ]),
            'provider' => 'openrouter',
            'model' => fake()->randomElement([
                'openai/gpt-4.1-mini',
                'anthropic/claude-3.5-haiku',
                'google/gemini-2.0-flash',
            ]),
            'vote' => ValidationDecision::ApprovedWithCaveats,
            'confidence' => fake()->numberBetween(60, 95),
            'flags' => [],
            'concerns' => [fake()->sentence()],
            'raw_response' => [
                'vote' => ValidationDecision::ApprovedWithCaveats->value,
                'rationale' => fake()->paragraph(),
            ],
            'metadata' => [
                'latency_ms' => fake()->numberBetween(750, 4000),
            ],
            'responded_at' => now(),
        ];
    }

    public function factual(): static
    {
        return $this->state(fn (): array => [
            'model_role' => TextGenerationRole::ValidatorFactual,
        ]);
    }

    public function contradiction(): static
    {
        return $this->state(fn (): array => [
            'model_role' => TextGenerationRole::ValidatorContradiction,
        ]);
    }

    public function userFit(): static
    {
        return $this->state(fn (): array => [
            'model_role' => TextGenerationRole::ValidatorUserFit,
        ]);
    }

    public function finalJudge(): static
    {
        return $this->state(fn (): array => [
            'model_role' => TextGenerationRole::FinalJudge,
            'vote' => ValidationDecision::ApprovedCurrent,
        ]);
    }
}
