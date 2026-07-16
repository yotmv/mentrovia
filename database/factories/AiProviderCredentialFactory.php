<?php

namespace Database\Factories;

use App\Models\AiProviderCredential;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderCredential>
 */
class AiProviderCredentialFactory extends Factory
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
            'provider' => 'openrouter',
            'secret' => 'sk-or-v1-'.fake()->sha256(),
            'fingerprint' => fake()->sha256(),
            'last_four' => fake()->bothify('??##'),
            'rotated_at' => null,
            'revoked_at' => null,
        ];
    }
}
