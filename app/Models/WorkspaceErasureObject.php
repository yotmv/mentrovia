<?php

namespace App\Models;

use Database\Factories\WorkspaceErasureObjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_erasure_progress_id
 * @property int $photo_storage_cleanup_id
 * @property string $disk
 * @property string $path
 * @property string $path_hash
 * @property string $source_type
 * @property string $source_id
 * @property Carbon|null $verified_missing_at
 */
#[Fillable([
    'workspace_erasure_progress_id', 'photo_storage_cleanup_id', 'disk', 'path',
    'path_hash', 'source_type', 'source_id', 'verified_missing_at',
])]

class WorkspaceErasureObject extends Model
{
    /** @use HasFactory<WorkspaceErasureObjectFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_missing_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkspaceErasureProgress, $this> */
    public function progress(): BelongsTo
    {
        return $this->belongsTo(WorkspaceErasureProgress::class, 'workspace_erasure_progress_id');
    }

    /** @return BelongsTo<PhotoStorageCleanup, $this> */
    public function cleanup(): BelongsTo
    {
        return $this->belongsTo(PhotoStorageCleanup::class, 'photo_storage_cleanup_id');
    }
}
