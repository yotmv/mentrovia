<?php

namespace App\Jobs;

use App\Actions\Users\EraseUserAccount;
use App\Exceptions\AccountErasureFailed;
use App\Models\AccountErasureProgress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;

#[WithoutRelations]
class EraseUserAccountData implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 300;

    public function __construct(public int $userId) {}

    public function handle(EraseUserAccount $eraseUserAccount): void
    {
        $maxChunks = max(1, min(100, (int) config('photostudio.account_erasure_chunks_per_job', 50)));

        for ($chunk = 0; $chunk < $maxChunks; $chunk++) {
            $before = AccountErasureProgress::query()->where('user_id', $this->userId)->first(['revision']);

            try {
                if ($eraseUserAccount->resume($this->userId)) {
                    return;
                }
            } catch (AccountErasureFailed $exception) {
                Log::critical('Account erasure attempt failed and will be retried.', [
                    'user_id' => $this->userId,
                    'exception_class' => $exception::class,
                ]);

                break;
            }

            $after = AccountErasureProgress::query()->where('user_id', $this->userId)->first(['revision']);

            if ($before?->revision === $after?->revision) {
                break;
            }
        }

        $this->release((int) config('photostudio.account_erasure_retry_seconds', 30));
    }

    public function failed(?\Throwable $exception): void
    {
        Log::critical('Account erasure job failed and remains recoverable from its progress row.', [
            'user_id' => $this->userId,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);
    }
}
