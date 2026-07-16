<?php

namespace Database\Factories;

use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Models\AiModelPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiModelPreference>
 */
class AiModelPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => fn (array $attributes): int => (int) User::query()->findOrFail((int) $attributes['user_id'])->current_account_id,
            'purpose' => AiModelPurpose::ShortText,
            'mode' => AiModelMode::Auto,
            'model_ids' => [],
        ];
    }
}
