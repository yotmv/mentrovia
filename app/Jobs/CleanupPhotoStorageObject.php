<?php

namespace App\Jobs;

use App\Models\PhotoStorageCleanup;
use App\Services\PhotoStorageCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Throwable;

#[WithoutRelations]
class CleanupPhotoStorageObject implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function __construct(public int $cleanupId) {}

    public function handle(PhotoStorageCleanupService $cleanupService): void
    {
        $cleanup = PhotoStorageCleanup::query()->find($this->cleanupId);

        if ($cleanup === null || $cleanup->completed_at !== null) {
            return;
        }

        if (! $cleanupService->attempt($cleanup->id)) {
            $this->release((int) config('photostudio.account_erasure_retry_seconds', 30));
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Photo storage cleanup job failed and remains recoverable from its outbox.', [
            'cleanup_id' => $this->cleanupId,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
