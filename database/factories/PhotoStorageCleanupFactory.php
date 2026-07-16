<?php

namespace Database\Factories;

use App\Models\PhotoStorageCleanup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoStorageCleanup>
 */
class PhotoStorageCleanupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'cleanup/'.fake()->uuid().'.jpg';

        return [
            'disk' => 's3',
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'attempts' => 0,
            'completed_at' => null,
            'last_attempted_at' => null,
            'enqueued_at' => null,
        ];
    }
}
