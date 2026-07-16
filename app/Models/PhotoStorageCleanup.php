<?php

namespace App\Models;

use Database\Factories\PhotoStorageCleanupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $path_hash
 * @property int $attempts
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_attempted_at
 * @property Carbon|null $enqueued_at
 */
#[Fillable(['disk', 'path', 'path_hash', 'attempts', 'completed_at', 'last_attempted_at', 'enqueued_at'])]
class PhotoStorageCleanup extends Model
{
    /** @use HasFactory<PhotoStorageCleanupFactory> */
    use HasFactory, MassPrunable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'completed_at' => 'datetime',
            'last_attempted_at' => 'datetime',
            'enqueued_at' => 'datetime',
        ];
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now()->subDays(30))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('account_erasure_cleanup')
                    ->whereColumn('account_erasure_cleanup.photo_storage_cleanup_id', 'photo_storage_cleanups.id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from((new WorkspaceErasureObject)->getTable())
                    ->whereColumn('workspace_erasure_objects.photo_storage_cleanup_id', 'photo_storage_cleanups.id');
            });
    }
}
