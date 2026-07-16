<?php

namespace Database\Factories;

use App\Models\PhotoOperationLease;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PhotoOperationLease>
 */
class PhotoOperationLeaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'project_id' => Project::factory(),
            'account_id' => fn (array $attributes): int => Project::query()->whereKey($attributes['project_id'])->firstOrFail()->account_id,
            'initiating_user_id' => fn (array $attributes): int => (int) Project::query()->whereKey($attributes['project_id'])->firstOrFail()->user_id,
            'protected_user_ids' => fn (array $attributes): array => [$attributes['initiating_user_id']],
            'purpose' => 'test-operation',
            'expires_at' => now()->addMinutes(10),
            'finished_at' => null,
        ];
    }
}
