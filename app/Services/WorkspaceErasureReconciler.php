<?php

namespace App\Services;

use App\Jobs\EraseWorkspaceData;
use App\Models\Account;
use App\Models\WorkspaceErasureProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class WorkspaceErasureReconciler
{
    public function __construct(private LifecycleRuntime $runtime) {}

    public function reconcile(int $limit): int
    {
        $this->runtime->assertReady();
        $staleBefore = now()->subSeconds(max(
            60,
            (int) config('photostudio.workspace_erasure_dispatch_stale_seconds', 600),
        ));
        $progressIds = WorkspaceErasureProgress::query()
            ->whereNull('completed_at')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('enqueued_at')
                    ->orWhere(fn ($stale) => $stale
                        ->whereNull('claimed_at')
                        ->where('enqueued_at', '<=', $staleBefore))
                    ->orWhere('claim_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $count = 0;

        foreach ($progressIds as $progressId) {
            $count += (int) $this->dispatch((int) $progressId);
        }

        return $count;
    }

    public function dispatch(int $progressId): bool
    {
        try {
            return DB::transaction(function () use ($progressId): bool {
                $accountId = WorkspaceErasureProgress::query()->whereKey($progressId)->value('account_id');
                $account = is_numeric($accountId)
                    ? Account::query()->lockForUpdate()->find((int) $accountId)
                    : null;
                $progress = WorkspaceErasureProgress::query()->lockForUpdate()->find($progressId);

                if ($progress === null || $progress->completed_at !== null) {
                    return false;
                }

                if ($account === null || $account->id !== $progress->account_id || $account->erasure_started_at === null) {
                    $progress->update(['last_error_code' => 'missing_erasure_fence']);

                    return false;
                }

                $dispatchToken = (string) Str::uuid7();
                $progress->update([
                    'dispatch_token' => $dispatchToken,
                    'enqueued_at' => now(),
                    'claimed_at' => null,
                    'claim_expires_at' => null,
                ]);
                $this->runtime->dispatchAfterCommit(
                    new EraseWorkspaceData($account->id, $dispatchToken),
                    $this->runtime->securityQueue(),
                );

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Workspace erasure reconciliation dispatch failed.', [
                'progress_id' => $progressId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }
}
