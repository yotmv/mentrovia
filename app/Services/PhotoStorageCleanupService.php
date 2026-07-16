<?php

namespace App\Services;

use App\Jobs\CleanupPhotoStorageObject;
use App\Models\PhotoStorageCleanup;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class PhotoStorageCleanupService
{
    public function __construct(
        private FilesystemFactory $filesystems,
        private LifecycleRuntime $runtime,
    ) {}

    /** @param array<int, string> $paths */
    public function deleteOrTrack(string $diskName, array $paths): bool
    {
        $paths = array_values(array_unique(array_filter($paths, fn (string $path): bool => $path !== '')));

        if ($paths === []) {
            return true;
        }

        $cleanupIds = $this->recordMany($diskName, $paths)->pluck('id')->all();
        $allMissing = true;

        foreach ($cleanupIds as $cleanupId) {
            if (! $this->attempt($cleanupId)) {
                $allMissing = false;
                $this->dispatch($cleanupId);
            }
        }

        return $allMissing;
    }

    public function track(string $disk, string $path): PhotoStorageCleanup
    {
        $cleanup = $this->record($disk, $path);

        if (! $this->dispatch($cleanup->id)) {
            throw new RuntimeException('Photo storage cleanup could not be enqueued atomically.');
        }

        return $cleanup;
    }

    public function record(string $disk, string $path): PhotoStorageCleanup
    {
        $cleanup = PhotoStorageCleanup::query()->firstOrCreate([
            'disk' => $disk,
            'path_hash' => hash('sha256', $path),
        ], ['path' => $path]);

        if ($cleanup->completed_at !== null) {
            $cleanup->update([
                'completed_at' => null,
                'enqueued_at' => null,
            ]);
        }

        return $cleanup;
    }

    /**
     * @param  array<int, string>  $paths
     * @return Collection<int, PhotoStorageCleanup>
     */
    public function recordMany(string $disk, array $paths): Collection
    {
        return collect(array_values(array_unique(array_filter($paths))))
            ->map(fn (string $path): PhotoStorageCleanup => $this->record($disk, $path));
    }

    /** @param array<int, int> $cleanupIds */
    public function deleteRecorded(array $cleanupIds): bool
    {
        $allMissing = true;

        foreach (array_values(array_unique($cleanupIds)) as $cleanupId) {
            if (! $this->attempt($cleanupId)) {
                $allMissing = false;
                $this->dispatch($cleanupId);
            }
        }

        return $allMissing;
    }

    public function attempt(int $cleanupId): bool
    {
        $cleanup = PhotoStorageCleanup::query()->find($cleanupId);

        if ($cleanup === null || $cleanup->completed_at !== null) {
            return true;
        }

        try {
            $disk = $this->filesystems->disk($cleanup->disk);
            $disk->delete($cleanup->path);
            $stillExists = $disk->exists($cleanup->path);
        } catch (Throwable $exception) {
            $this->recordAttempt($cleanup);

            Log::critical('Photo storage cleanup attempt failed.', [
                'cleanup_id' => $cleanup->id,
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        $this->recordAttempt($cleanup);

        if ($stillExists) {
            return false;
        }

        $cleanup->update(['completed_at' => now()]);

        return true;
    }

    public function dispatch(int $cleanupId): bool
    {
        try {
            return DB::transaction(function () use ($cleanupId): bool {
                $cleanup = PhotoStorageCleanup::query()->lockForUpdate()->find($cleanupId);

                if ($cleanup === null || $cleanup->completed_at !== null || $cleanup->enqueued_at !== null) {
                    return false;
                }

                $cleanup->update(['enqueued_at' => now()]);
                $this->runtime->dispatch(new CleanupPhotoStorageObject($cleanupId), $this->runtime->photoQueue());

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Photo storage cleanup enqueue failed atomically.', [
                'cleanup_id' => $cleanupId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function recordAttempt(PhotoStorageCleanup $cleanup): void
    {
        $cleanup->increment('attempts');
        $cleanup->update(['last_attempted_at' => now()]);
    }
}
