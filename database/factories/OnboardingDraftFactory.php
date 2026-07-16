<?php

namespace Database\Factories;

use App\Enums\BusinessOnboardingTrack;
use App\Models\Account;
use App\Models\OnboardingDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingDraft>
 */
class OnboardingDraftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'track' => BusinessOnboardingTrack::NewCompany,
            'current_step' => 1,
            'payload' => [],
            'schema_version' => 1,
            'revision' => 1,
            'last_saved_by_user_id' => null,
            'expires_at' => now()->addDays(180),
        ];
    }
}
