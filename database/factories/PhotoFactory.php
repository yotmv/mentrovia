<?php

namespace Database\Factories;

use App\Enums\PhotoCostSource;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'photo_generation_batch_id' => null,
            'kind' => PhotoKind::Uploaded,
            'disk' => 's3',
            'path' => config('photostudio.uploaded_prefix').fake()->uuid().'/original.jpg',
            'processing_status' => PhotoProcessingStatus::Ready,
            'original_filename' => fake()->word().'.jpg',
            'text' => fake()->sentence(),
            'text_source' => PhotoTextSource::User,
            'provider' => null,
            'model' => null,
            'mode' => null,
            'cost_usd' => null,
            'cost_source' => null,
        ];
    }

    public function generated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PhotoKind::Generated,
            'photo_generation_batch_id' => PhotoGenerationBatch::factory(),
            'path' => config('photostudio.generated_prefix').fake()->uuid().'/original.png',
            'original_filename' => null,
            'text' => fake()->sentence(),
            'text_source' => PhotoTextSource::Auto,
            'provider' => 'openrouter',
            'model' => 'google/gemini-2.5-flash-image',
            'mode' => PhotoMode::Cleanup,
            'cost_usd' => 0.039,
            'cost_source' => PhotoCostSource::Estimate,
        ]);
    }

    public function withDerivatives(): static
    {
        return $this->state(function (array $attributes): array {
            $directory = dirname($attributes['path']);

            $variants = $attributes['kind'] === PhotoKind::Generated
                ? ['master' => 'master.webp', 'hero' => 'hero.webp', 'hero-jpg' => 'hero-jpg.jpg', 'card' => 'card.webp', 'thumb' => 'thumb.webp']
                : ['llm-input' => 'llm-input.jpg', 'card' => 'card.webp', 'thumb' => 'thumb.webp'];

            return [
                'processing_status' => PhotoProcessingStatus::Ready,
                'processed_at' => now(),
                'width' => 3000,
                'height' => 2000,
                'size_bytes' => 4_200_000,
                'derivatives' => collect($variants)->map(fn (string $file) => [
                    'path' => $directory.'/'.$file,
                    'width' => 1200,
                    'height' => 800,
                    'size_bytes' => 120_000,
                ])->all(),
            ];
        });
    }

    public function autoDescribed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'text' => fake()->sentence(),
            'text_source' => PhotoTextSource::Auto,
        ]);
    }

    public function uncaptioned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'text' => null,
            'text_source' => null,
        ]);
    }
}
