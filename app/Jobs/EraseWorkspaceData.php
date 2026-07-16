<?php

namespace App\Jobs;

use App\Services\WorkspaceErasureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Throwable;

#[WithoutRelations]
class EraseWorkspaceData implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 300;

    public function __construct(
        public int $accountId,
        public string $dispatchToken,
    ) {}

    public function handle(WorkspaceErasureService $service): void
    {
        if (! $service->claim($this->accountId, $this->dispatchToken)) {
            return;
        }

        $maximumChunks = max(1, min(100, (int) config('photostudio.workspace_erasure_chunks_per_job', 25)));

        try {
            for ($chunk = 0; $chunk < $maximumChunks; $chunk++) {
                if ($service->resume($this->accountId, $this->dispatchToken)) {
                    return;
                }
            }
        } catch (Throwable $exception) {
            $service->recordFailure($this->accountId, $this->dispatchToken, $exception);
            Log::critical('Workspace erasure chunk failed and remains resumable.', [
                'account_id' => $this->accountId,
                'exception_class' => $exception::class,
            ]);
        }

        $service->redispatch(
            $this->accountId,
            $this->dispatchToken,
            (int) config('photostudio.workspace_erasure_retry_seconds', 30),
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('Workspace erasure worker failed and will be repaired by reconciliation.', [
            'account_id' => $this->accountId,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
