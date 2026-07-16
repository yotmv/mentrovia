<?php

namespace Database\Factories;

use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiOperationAudit>
 */
class AiOperationAuditFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operation_id' => (string) Str::uuid7(),
            'account_id' => Account::factory(),
            'actor_user_id' => User::factory(),
            'event' => AiAuditEvent::Succeeded,
            'purpose' => AiModelPurpose::ShortText,
            'provider' => 'openrouter',
            'model' => 'openrouter/auto',
            'credential_fingerprint' => fake()->sha256(),
            'request_hash' => fake()->sha256(),
            'request_bytes' => fake()->numberBetween(1, 4096),
            'cost_usd' => fake()->randomFloat(6, 0, 2),
            'occurred_at' => now(),
        ];
    }
}
