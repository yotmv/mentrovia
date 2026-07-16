<?php

namespace App\Services;

use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoCostSource;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoMode;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\GeneratePhotoWithModel;
use App\Models\Account;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PhotoGenerationSlotService
{
    public function __construct(private LifecycleRuntime $runtime) {}

    /**
     * @param  array<int, array{provider: string, model: string, uses_byok?: bool}>  $models
     * @return Collection<int, PhotoGenerationSlot>
     */
    public function createAndEnqueue(PhotoGenerationBatch $batch, array $models, PhotoMode $mode): Collection
    {
        $this->runtime->assertReady();

        return DB::transaction(function () use ($batch, $models, $mode): Collection {
            $account = Account::query()->lockForUpdate()->find($batch->account_id);

            if ($account === null || $account->erasure_started_at !== null) {
                return new Collection;
            }

            $lockedBatch = PhotoGenerationBatch::query()->lockForUpdate()->findOrFail($batch->id);
            $slots = new Collection;

            foreach ($models as $model) {
                $operationUuid = (string) Str::uuid7();
                $slot = PhotoGenerationSlot::query()->firstOrCreate(
                    [
                        'photo_generation_batch_id' => $lockedBatch->id,
                        'provider' => $model['provider'],
                        'model' => $model['model'],
                    ],
                    [
                        'mode' => $mode->value,
                        'uses_byok' => $model['uses_byok'] ?? false,
                        'operation_uuid' => $operationUuid,
                        'status' => PhotoGenerationSlotStatus::Pending,
                        'staging_prefix' => (string) config('photostudio.generated_prefix').'staging/'.$operationUuid.'/',
                    ],
                );

                if ($slot->enqueued_at === null) {
                    $slot->update([
                        'status' => PhotoGenerationSlotStatus::Queued,
                        'enqueued_at' => now(),
                    ]);

                    $this->runtime->dispatch(new GeneratePhotoWithModel($slot->id), $this->runtime->photoQueue());
                }

                $slots->push($slot);
            }

            return $slots;
        }, attempts: 3);
    }

    public function claim(int $slotId): ?PhotoGenerationSlotClaim
    {
        $this->runtime->assertReady();

        return DB::transaction(function () use ($slotId): ?PhotoGenerationSlotClaim {
            $account = $this->lockAccountForSlot($slotId);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($slotId);

            if ($slot === null || $slot->status->isTerminal()) {
                return null;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $this->failForWorkspaceErasureLocked($slot);

                return null;
            }

            if ($slot->status === PhotoGenerationSlotStatus::ProviderStarted) {
                $slot->update([
                    'status' => PhotoGenerationSlotStatus::Ambiguous,
                    'failure_code' => 'provider_started_worker_lost',
                    'manual_review_at' => now(),
                    'claim_expires_at' => null,
                ]);
                $this->finalizeBatchLocked($slot->photo_generation_batch_id);

                return null;
            }

            if ($slot->status === PhotoGenerationSlotStatus::Claimed
                && $slot->claim_expires_at !== null
                && $slot->claim_expires_at->isFuture()
            ) {
                return null;
            }

            $resumeStaged = $slot->status === PhotoGenerationSlotStatus::Staged;
            $executionToken = (string) Str::uuid7();
            $fence = $slot->fence + 1;
            $slot->update([
                'status' => $resumeStaged ? PhotoGenerationSlotStatus::Staged : PhotoGenerationSlotStatus::Claimed,
                'execution_token' => $executionToken,
                'fence' => $fence,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addSeconds(max(60, (int) config('photostudio.lifecycle.claim_seconds', 600))),
                'failure_code' => null,
            ]);

            return new PhotoGenerationSlotClaim($slot->fresh(), $executionToken, $fence, $resumeStaged);
        }, attempts: 3);
    }

    public function markProviderStarted(PhotoGenerationSlotClaim $claim): bool
    {
        return DB::transaction(function () use ($claim): bool {
            $account = $this->lockAccountForSlot($claim->slot->id);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($claim->slot->id);

            if (! $this->claimMatches($slot, $claim, PhotoGenerationSlotStatus::Claimed)) {
                return false;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $this->failForWorkspaceErasureLocked($slot);

                return false;
            }

            return $slot->update([
                'status' => PhotoGenerationSlotStatus::ProviderStarted,
                'provider_started_at' => now(),
            ]);
        }, attempts: 3);
    }

    public function recordStaged(
        PhotoGenerationSlotClaim $claim,
        string $disk,
        string $path,
        string $actualProvider,
        string $actualModel,
        ?float $actualCostUsd,
        PhotoCostSource $actualCostSource,
    ): bool {
        return DB::transaction(function () use ($claim, $disk, $path, $actualProvider, $actualModel, $actualCostUsd, $actualCostSource): bool {
            $account = $this->lockAccountForSlot($claim->slot->id);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($claim->slot->id);

            if (! $this->claimMatches($slot, $claim, PhotoGenerationSlotStatus::ProviderStarted)) {
                return false;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $this->failForWorkspaceErasureLocked($slot);

                return false;
            }

            return $slot->update([
                'status' => PhotoGenerationSlotStatus::Staged,
                'staged_disk' => $disk,
                'staged_path' => $path,
                'actual_provider' => $actualProvider,
                'actual_model' => $actualModel,
                'actual_cost_usd' => $actualCostUsd,
                'actual_cost_source' => $actualCostSource,
            ]);
        }, attempts: 3);
    }

    public function releaseClaim(PhotoGenerationSlotClaim $claim): bool
    {
        return DB::transaction(function () use ($claim): bool {
            $this->lockAccountForSlot($claim->slot->id);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($claim->slot->id);

            if (! $this->claimMatches($slot, $claim, PhotoGenerationSlotStatus::Claimed)) {
                return false;
            }

            return $slot->update([
                'status' => PhotoGenerationSlotStatus::Queued,
                'execution_token' => null,
                'claim_expires_at' => null,
                'failure_code' => null,
            ]);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $photoAttributes */
    public function completeStaged(PhotoGenerationSlotClaim $claim, array $photoAttributes): ?Photo
    {
        return DB::transaction(function () use ($claim, $photoAttributes): ?Photo {
            $account = $this->lockAccountForSlot($claim->slot->id);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($claim->slot->id);

            if (! $this->claimMatches($slot, $claim, PhotoGenerationSlotStatus::Staged)
                || $slot->staged_path === null
                || $slot->staged_disk === null
            ) {
                return null;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $this->failForWorkspaceErasureLocked($slot);

                return null;
            }

            PhotoGenerationBatch::query()->lockForUpdate()->findOrFail($slot->photo_generation_batch_id);
            $provider = $slot->actual_provider ?? $slot->provider;
            $model = $slot->actual_model ?? $slot->model;
            $photo = Photo::query()
                ->where('photo_generation_batch_id', $slot->photo_generation_batch_id)
                ->where('provider', $provider)
                ->where('model', $model)
                ->lockForUpdate()
                ->first();

            if ($photo !== null && ($photo->path !== $slot->staged_path || $photo->disk !== $slot->staged_disk)) {
                $slot->update([
                    'status' => PhotoGenerationSlotStatus::Ambiguous,
                    'failure_code' => 'photo_slot_conflict',
                    'manual_review_at' => now(),
                    'claim_expires_at' => null,
                ]);
                $this->finalizeBatchLocked($slot->photo_generation_batch_id);

                return null;
            }

            $photo ??= Photo::query()->create($photoAttributes);

            if ($photo->derivatives_enqueued_at === null) {
                $photo->update(['derivatives_enqueued_at' => now()]);
                $this->runtime->dispatch(new GeneratePhotoDerivatives($photo), $this->runtime->photoQueue());
            }

            $slot->update([
                'status' => PhotoGenerationSlotStatus::Completed,
                'photo_id' => $photo->id,
                'completed_at' => now(),
                'claim_expires_at' => null,
                'failure_code' => null,
            ]);
            $this->finalizeBatchLocked($slot->photo_generation_batch_id);

            return $photo;
        }, attempts: 3);
    }

    public function markAmbiguous(PhotoGenerationSlotClaim $claim, string $failureCode): bool
    {
        return DB::transaction(function () use ($claim, $failureCode): bool {
            $this->lockAccountForSlot($claim->slot->id);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($claim->slot->id);

            if ($slot === null
                || $slot->execution_token !== $claim->executionToken
                || $slot->fence !== $claim->fence
                || $slot->status->isTerminal()
            ) {
                return false;
            }

            $updated = $slot->update([
                'status' => PhotoGenerationSlotStatus::Ambiguous,
                'failure_code' => Str::limit($failureCode, 80, ''),
                'manual_review_at' => now(),
                'claim_expires_at' => null,
            ]);
            $this->finalizeBatchLocked($slot->photo_generation_batch_id);

            return $updated;
        }, attempts: 3);
    }

    public function markPreProviderFailed(PhotoGenerationSlotClaim $claim, string $failureCode): bool
    {
        return DB::transaction(function () use ($claim, $failureCode): bool {
            $this->lockAccountForSlot($claim->slot->id);
            $slot = PhotoGenerationSlot::query()->lockForUpdate()->find($claim->slot->id);

            if (! $this->claimMatches($slot, $claim, PhotoGenerationSlotStatus::Claimed)) {
                return false;
            }

            $updated = $slot->update([
                'status' => PhotoGenerationSlotStatus::Failed,
                'failure_code' => Str::limit($failureCode, 80, ''),
                'claim_expires_at' => null,
                'completed_at' => now(),
            ]);
            $this->finalizeBatchLocked($slot->photo_generation_batch_id);

            return $updated;
        }, attempts: 3);
    }

    public function finalizeBatch(int $batchId): void
    {
        DB::transaction(function () use ($batchId): void {
            $this->lockAccountForBatch($batchId);
            PhotoGenerationBatch::query()->lockForUpdate()->find($batchId);
            $this->finalizeBatchLocked($batchId);
        }, attempts: 3);
    }

    private function claimMatches(
        ?PhotoGenerationSlot $slot,
        PhotoGenerationSlotClaim $claim,
        PhotoGenerationSlotStatus $status,
    ): bool {
        return $slot !== null
            && $slot->status === $status
            && $slot->execution_token === $claim->executionToken
            && $slot->fence === $claim->fence;
    }

    private function finalizeBatchLocked(int $batchId): void
    {
        $batch = PhotoGenerationBatch::query()->find($batchId);

        if ($batch === null) {
            return;
        }

        $slots = PhotoGenerationSlot::query()->where('photo_generation_batch_id', $batchId)->get(['status']);

        if ($slots->isEmpty() || $slots->contains(fn (PhotoGenerationSlot $slot): bool => ! $slot->status->isTerminal())) {
            $batch->update(['status' => GenerationBatchStatus::Processing]);

            return;
        }

        $completed = $slots->contains(fn (PhotoGenerationSlot $slot): bool => $slot->status === PhotoGenerationSlotStatus::Completed);
        $requiresReview = $slots->contains(fn (PhotoGenerationSlot $slot): bool => $slot->status === PhotoGenerationSlotStatus::Ambiguous);

        $batch->update([
            'status' => $completed ? GenerationBatchStatus::Completed : GenerationBatchStatus::Failed,
            'error' => $requiresReview
                ? 'One or more image generations require manual review.'
                : ($completed ? null : 'Every selected model failed before producing a usable image.'),
        ]);
    }

    private function lockAccountForSlot(int $slotId): ?Account
    {
        $accountId = PhotoGenerationSlot::query()
            ->join('photo_generation_batches', 'photo_generation_batches.id', '=', 'photo_generation_slots.photo_generation_batch_id')
            ->where('photo_generation_slots.id', $slotId)
            ->value('photo_generation_batches.account_id');

        return is_numeric($accountId)
            ? Account::query()->lockForUpdate()->find((int) $accountId)
            : null;
    }

    private function lockAccountForBatch(int $batchId): ?Account
    {
        $accountId = PhotoGenerationBatch::query()->whereKey($batchId)->value('account_id');

        return is_numeric($accountId)
            ? Account::query()->lockForUpdate()->find((int) $accountId)
            : null;
    }

    private function failForWorkspaceErasureLocked(PhotoGenerationSlot $slot): void
    {
        if ($slot->status->isTerminal()) {
            return;
        }

        $slot->update([
            'status' => PhotoGenerationSlotStatus::Failed,
            'failure_code' => 'workspace_erasure_started',
            'execution_token' => null,
            'claim_expires_at' => null,
            'completed_at' => now(),
        ]);
        $this->finalizeBatchLocked($slot->photo_generation_batch_id);
    }
}
