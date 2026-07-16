<?php

namespace Database\Factories;

use App\Enums\BusinessProfileVersionSource;
use App\Models\Business;
use App\Models\BusinessProfileVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfileVersion>
 */
class BusinessProfileVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'revision' => 1,
            'fingerprint' => hash('sha256', fake()->uuid()),
            'schema_version' => 1,
            'source' => BusinessProfileVersionSource::Backfill,
            'sections' => [],
            'changed_field_keys' => [],
            'snapshot' => ['business' => [], 'profile_answers' => []],
            'source_metadata' => null,
            'created_by_user_id' => null,
        ];
    }

    public function forBusiness(Business $business, ?User $creator = null): static
    {
        return $this->state(fn (): array => [
            'business_id' => $business->id,
            'created_by_user_id' => $creator?->id,
        ]);
    }
}
