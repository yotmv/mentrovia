<?php

namespace Database\Factories;

use App\Models\PhotoStorageCleanup;
use App\Models\WorkspaceErasureObject;
use App\Models\WorkspaceErasureProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceErasureObject>
 */
class WorkspaceErasureObjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'workspace-erasure/'.fake()->uuid().'.jpg';

        return [
            'workspace_erasure_progress_id' => WorkspaceErasureProgress::factory(),
            'photo_storage_cleanup_id' => PhotoStorageCleanup::factory()->state([
                'path' => $path,
                'path_hash' => hash('sha256', $path),
            ]),
            'disk' => 's3',
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'source_type' => 'photo',
            'source_id' => (string) fake()->numberBetween(1, 1_000_000),
            'verified_missing_at' => null,
        ];
    }
}
