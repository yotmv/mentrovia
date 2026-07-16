<?php

namespace Database\Factories;

use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoMode;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PhotoGenerationSlot>
 */
class PhotoGenerationSlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'photo_generation_batch_id' => PhotoGenerationBatch::factory(),
            'provider' => 'openrouter',
            'model' => 'google/gemini-2.5-flash-image',
            'mode' => PhotoMode::Cleanup,
            'operation_uuid' => (string) Str::uuid7(),
            'status' => PhotoGenerationSlotStatus::Pending,
            'fence' => 0,
            'staging_prefix' => config('photostudio.generated_prefix').'staging/'.Str::uuid7().'/',
        ];
    }
}
