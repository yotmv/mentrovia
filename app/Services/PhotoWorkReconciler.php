<?php

namespace App\Services;

use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoKind;
use App\Enums\PhotoProcessingStatus;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\EraseUserAccountData;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\GeneratePhotoWithModel;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\Account;
use App\Models\AccountErasureProgress;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\PhotoStorageCleanup;
use App\Models\User;
use App\Models\WorkspaceErasureProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PhotoWorkReconciler
{
    public function __construct(
        private LifecycleRuntime $runtime,
        private PhotoStorageCleanupService $cleanupService,
        private WorkspaceErasureReconciler $workspaceErasureReconciler,
    ) {}

    public function schedulePhoto(Photo $photo): void
    {
        if (! $this->dispatchPhoto($photo->id)) {
            throw new RuntimeException('Photo derivative work could not be enqueued atomically.');
        }
    }

    public function scheduleBatch(PhotoGenerationBatch $batch): void
    {
        if (! $this->dispatchBatch($batch->id)) {
            throw new RuntimeException('Photo analysis work could not be enqueued atomically.');
        }
    }

    public function scheduleDescription(Photo $photo): void
    {
        if (! $this->dispatchDescription($photo->id)) {
            throw new RuntimeException('Photo description work could not be enqueued atomically.');
        }
    }

    /**
     * @return array{cleanups: int, erasures: int, workspace_erasures: int, photos: int, descriptions: int, batches: int, slots: int}
     */
    public function reconcile(int $limit): array
    {
        $this->runtime->assertReady();

        $userIds = User::query()
            ->whereNotNull('account_erasure_started_at')
            ->whereNotIn('id', AccountErasureProgress::query()->select('user_id'))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($userIds as $userId) {
            $this->ensureErasureProgress((int) $userId);
        }

        $cleanupIds = PhotoStorageCleanup::query()
            ->whereNull('completed_at')
            ->whereNull('enqueued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $erasureIds = AccountErasureProgress::query()
            ->whereHas('user', fn ($query) => $query->whereNotNull('account_erasure_started_at'))
            ->whereNull('enqueued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $photoIds = Photo::query()
            ->whereHas('account', fn ($query) => $query->whereNull('erasure_started_at'))
            ->whereIn('processing_status', [
                PhotoProcessingStatus::Pending,
                PhotoProcessingStatus::Processing,
                PhotoProcessingStatus::Failed,
            ])
            ->whereNull('derivatives_enqueued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $descriptionIds = Photo::query()
            ->whereHas('account', fn ($query) => $query->whereNull('erasure_started_at'))
            ->where('kind', PhotoKind::Uploaded)
            ->where('processing_status', PhotoProcessingStatus::Ready)
            ->whereNull('text')
            ->whereNull('text_source')
            ->where('description_state', 'pending')
            ->whereNull('description_enqueued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $batchIds = PhotoGenerationBatch::query()
            ->whereHas('account', fn ($query) => $query->whereNull('erasure_started_at'))
            ->where('status', GenerationBatchStatus::Pending)
            ->where('analysis_state', 'pending')
            ->whereNull('analysis_enqueued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $slotIds = PhotoGenerationSlot::query()
            ->whereHas('generationBatch.account', fn ($query) => $query->whereNull('erasure_started_at'))
            ->where('status', PhotoGenerationSlotStatus::Pending)
            ->whereNull('enqueued_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $counts = [
            'cleanups' => 0,
            'erasures' => 0,
            'workspace_erasures' => $this->workspaceErasureReconciler->reconcile($limit),
            'photos' => 0,
            'descriptions' => 0,
            'batches' => 0,
            'slots' => 0,
        ];

        foreach ($cleanupIds as $cleanupId) {
            $counts['cleanups'] += (int) $this->cleanupService->dispatch((int) $cleanupId);
        }

        foreach ($erasureIds as $progressId) {
            $counts['erasures'] += (int) $this->dispatchErasure((int) $progressId);
        }

        foreach ($photoIds as $photoId) {
            $counts['photos'] += (int) $this->dispatchPhoto((int) $photoId);
        }

        foreach ($descriptionIds as $photoId) {
            $counts['descriptions'] += (int) $this->dispatchDescription((int) $photoId);
        }

        foreach ($batchIds as $batchId) {
            $counts['batches'] += (int) $this->dispatchBatch((int) $batchId);
        }

        foreach ($slotIds as $slotId) {
            $counts['slots'] += (int) $this->dispatchSlot((int) $slotId);
        }

        $this->logOldPendingWork();

        return $counts;
    }

    public function dispatchPhoto(int $photoId): bool
    {
        try {
            return DB::transaction(function () use ($photoId): bool {
                $accountId = Photo::query()->whereKey($photoId)->value('account_id');
                $account = is_numeric($accountId)
                    ? Account::query()->lockForUpdate()->find((int) $accountId)
                    : null;
                $photo = Photo::query()->lockForUpdate()->find($photoId);

                if ($account === null
                    || $account->erasure_started_at !== null
                    || $photo === null
                    || $photo->derivatives_enqueued_at !== null
                    || ! in_array($photo->processing_status, [
                        PhotoProcessingStatus::Pending,
                        PhotoProcessingStatus::Processing,
                        PhotoProcessingStatus::Failed,
                    ], true)
                ) {
                    return false;
                }

                $photo->update(['derivatives_enqueued_at' => now()]);
                $this->runtime->dispatch(new GeneratePhotoDerivatives($photo), $this->runtime->photoQueue());

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Photo derivative enqueue failed atomically.', [
                'photo_id' => $photoId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function dispatchBatch(int $batchId): bool
    {
        try {
            return DB::transaction(function () use ($batchId): bool {
                $accountId = PhotoGenerationBatch::query()->whereKey($batchId)->value('account_id');
                $account = is_numeric($accountId)
                    ? Account::query()->lockForUpdate()->find((int) $accountId)
                    : null;
                $batch = PhotoGenerationBatch::query()->lockForUpdate()->find($batchId);

                if ($account === null
                    || $account->erasure_started_at !== null
                    || $batch === null
                    || $batch->analysis_enqueued_at !== null
                    || $batch->status !== GenerationBatchStatus::Pending
                    || $batch->analysis_state !== 'pending'
                ) {
                    return false;
                }

                $batch->update([
                    'analysis_enqueued_at' => now(),
                    'analysis_state' => 'queued',
                    'analysis_operation_uuid' => $batch->analysis_operation_uuid ?? (string) Str::uuid7(),
                ]);
                $this->runtime->dispatch(new RunPhotoGenerationBatch($batch), $this->runtime->photoQueue());

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Photo batch enqueue failed atomically.', [
                'batch_id' => $batchId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function dispatchDescription(int $photoId): bool
    {
        try {
            return DB::transaction(function () use ($photoId): bool {
                $accountId = Photo::query()->whereKey($photoId)->value('account_id');
                $account = is_numeric($accountId)
                    ? Account::query()->lockForUpdate()->find((int) $accountId)
                    : null;
                $photo = Photo::query()->lockForUpdate()->find($photoId);

                if ($account === null
                    || $account->erasure_started_at !== null
                    || $photo === null
                    || ! $photo->isUploaded()
                    || $photo->processing_status !== PhotoProcessingStatus::Ready
                    || filled($photo->text)
                    || $photo->text_source !== null
                    || $photo->description_state !== 'pending'
                    || $photo->description_enqueued_at !== null
                ) {
                    return false;
                }

                $photo->update([
                    'description_enqueued_at' => now(),
                    'description_state' => 'queued',
                    'description_operation_uuid' => $photo->description_operation_uuid ?? (string) Str::uuid7(),
                ]);
                $this->runtime->dispatch(new DescribeUploadedPhoto($photo), $this->runtime->photoQueue());

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Photo description enqueue failed atomically.', [
                'photo_id' => $photoId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function dispatchSlot(int $slotId): bool
    {
        try {
            return DB::transaction(function () use ($slotId): bool {
                $accountId = PhotoGenerationSlot::query()
                    ->whereKey($slotId)
                    ->join('photo_generation_batches', 'photo_generation_batches.id', '=', 'photo_generation_slots.photo_generation_batch_id')
                    ->value('photo_generation_batches.account_id');
                $account = is_numeric($accountId)
                    ? Account::query()->lockForUpdate()->find((int) $accountId)
                    : null;
                $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($slotId);

                if ($account === null
                    || $account->erasure_started_at !== null
                    || $slot === null
                    || $slot->status !== PhotoGenerationSlotStatus::Pending
                    || $slot->enqueued_at !== null
                ) {
                    return false;
                }

                $slot->update(['status' => PhotoGenerationSlotStatus::Queued, 'enqueued_at' => now()]);
                $this->runtime->dispatch(new GeneratePhotoWithModel($slot->id), $this->runtime->photoQueue());

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Photo generation slot enqueue failed atomically.', [
                'slot_id' => $slotId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function ensureErasureProgress(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            AccountErasureProgress::query()->firstOrCreate(['user_id' => $userId]);
        }, attempts: 3);
    }

    private function dispatchErasure(int $progressId): bool
    {
        try {
            return DB::transaction(function () use ($progressId): bool {
                $progress = AccountErasureProgress::query()->with('user')->lockForUpdate()->find($progressId);

                if ($progress === null || $progress->user?->account_erasure_started_at === null || $progress->enqueued_at !== null) {
                    return false;
                }

                $progress->update(['enqueued_at' => now()]);
                $this->runtime->dispatch(new EraseUserAccountData($progress->user_id), $this->runtime->securityQueue());

                return true;
            }, attempts: 3);
        } catch (Throwable $exception) {
            Log::critical('Account erasure enqueue failed atomically.', [
                'progress_id' => $progressId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function logOldPendingWork(): void
    {
        $warningBefore = now()->subSeconds((int) config('photostudio.reconciliation.warning_age_seconds', 3600));
        $oldCleanups = PhotoStorageCleanup::query()->whereNull('completed_at')->where('created_at', '<=', $warningBefore)->count();
        $oldErasures = AccountErasureProgress::query()->where('created_at', '<=', $warningBefore)->count();
        $oldWorkspaceErasures = WorkspaceErasureProgress::query()
            ->whereNull('completed_at')
            ->where('created_at', '<=', $warningBefore)
            ->count();

        if ($oldCleanups > 0 || $oldErasures > 0 || $oldWorkspaceErasures > 0) {
            Log::warning('Durable lifecycle work remains pending beyond its warning threshold.', [
                'cleanup_count' => $oldCleanups,
                'account_erasure_count' => $oldErasures,
                'workspace_erasure_count' => $oldWorkspaceErasures,
            ]);
        }
    }
}
