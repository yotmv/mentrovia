<?php

namespace Database\Factories;

use App\Models\AiAccountSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiAccountSetting>
 */
class AiAccountSettingFactory extends Factory
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
            'paid_ai_enabled' => true,
            'hosted_ai_enabled' => true,
            'byok_enabled' => false,
            'monthly_usd_limit' => null,
            'per_operation_usd_limit' => null,
            'max_concurrency' => 1,
        ];
    }
}
