<?php

namespace App\Services;

use App\Enums\AiAuditEvent;
use App\Exceptions\WorkspaceErasureFailed;
use App\Jobs\EraseWorkspaceData;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\AgentConversation;
use App\Models\AiOperationAudit;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\PhotoOperationLease;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\WorkspaceErasureObject;
use App\Models\WorkspaceErasureProgress;
use App\Services\Billing\StripeCustomerTeardown;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class WorkspaceErasureService
{
    public function __construct(
        private FilesystemFactory $filesystems,
        private PhotoStorageCleanupService $cleanupService,
        private LifecycleRuntime $runtime,
        private StripeCustomerTeardown $stripeCustomerTeardown,
    ) {}

    public function claim(int $accountId, string $dispatchToken): bool
    {
        return DB::transaction(function () use ($accountId, $dispatchToken): bool {
            $progress = WorkspaceErasureProgress::query()
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->first();

            if ($progress === null
                || $progress->completed_at !== null
                || ! is_string($progress->dispatch_token)
                || ! hash_equals($progress->dispatch_token, $dispatchToken)
                || ($progress->claimed_at !== null && $progress->claim_expires_at?->isFuture())
            ) {
                return false;
            }

            $progress->increment('attempts', 1, [
                'claimed_at' => now(),
                'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
                'last_error_code' => null,
            ]);

            return true;
        }, attempts: 3);
    }

    public function resume(int $accountId, string $dispatchToken): bool
    {
        $progress = WorkspaceErasureProgress::query()
            ->where('account_id', $accountId)
            ->where('dispatch_token', $dispatchToken)
            ->first();

        if ($progress === null || $progress->completed_at !== null) {
            return true;
        }

        return match ($progress->phase) {
            'drain_work' => $this->drainWork($progress, $dispatchToken),
            'scan_photos' => $this->scanPhotoChunk($progress, $dispatchToken),
            'scan_staging' => $this->scanStagingChunk($progress, $dispatchToken),
            'cleanup_storage' => $this->cleanupStorageChunk($progress, $dispatchToken),
            'verify_storage' => $this->verifyStorageChunk($progress, $dispatchToken),
            'purge_nonstorage' => $this->purgeNonstorageChunk($progress, $dispatchToken),
            'teardown_billing' => $this->teardownBilling($progress, $dispatchToken),
            'delete_account' => $this->deleteAccount($progress, $dispatchToken),
            'completed' => true,
            default => throw WorkspaceErasureFailed::database(),
        };
    }

    public function redispatch(int $accountId, string $dispatchToken, int $delaySeconds = 0): bool
    {
        return DB::transaction(function () use ($accountId, $dispatchToken, $delaySeconds): bool {
            $progress = WorkspaceErasureProgress::query()
                ->where('account_id', $accountId)
                ->lockForUpdate()
                ->first();

            if ($progress === null
                || $progress->completed_at !== null
                || ! is_string($progress->dispatch_token)
                || ! hash_equals($progress->dispatch_token, $dispatchToken)
            ) {
                return false;
            }

            $nextToken = (string) Str::uuid7();
            $progress->update([
                'dispatch_token' => $nextToken,
                'enqueued_at' => now(),
                'claimed_at' => null,
                'claim_expires_at' => null,
            ]);
            $job = new EraseWorkspaceData($accountId, $nextToken);

            if ($delaySeconds > 0) {
                $job->delay(now()->addSeconds($delaySeconds));
            }

            $this->runtime->dispatchAfterCommit($job, $this->runtime->securityQueue());

            return true;
        }, attempts: 3);
    }

    public function recordFailure(int $accountId, string $dispatchToken, Throwable $exception): void
    {
        WorkspaceErasureProgress::query()
            ->where('account_id', $accountId)
            ->where('dispatch_token', $dispatchToken)
            ->whereNull('completed_at')
            ->update([
                'last_error_code' => Str::limit(class_basename($exception), 80, ''),
                'claim_expires_at' => now(),
            ]);
    }

    private function drainWork(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        return DB::transaction(function () use ($progress, $dispatchToken): bool {
            $account = Account::query()->lockForUpdate()->find($progress->account_id);
            $locked = $this->lockProgress($progress, $dispatchToken, 'drain_work');

            if ($locked === null || $account === null || $account->erasure_started_at === null) {
                throw WorkspaceErasureFailed::database();
            }

            if ($this->hasActiveWork($account->id)) {
                $locked->update([
                    'last_progress_at' => now(),
                    'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
                ]);

                return false;
            }

            $this->advance($locked, 'scan_photos');

            return false;
        }, attempts: 3);
    }

    private function scanPhotoChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        DB::transaction(function () use ($progress, $dispatchToken): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'scan_photos');

            if ($locked === null) {
                return;
            }

            $photos = Photo::query()
                ->where('account_id', $locked->account_id)
                ->where('id', '>', $locked->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'account_id', 'disk', 'path', 'derivatives']);

            if ($photos->isEmpty()) {
                $this->advance($locked, 'scan_staging');

                return;
            }

            foreach ($photos as $photo) {
                $knownPaths = collect($photo->derivatives ?? [])->pluck('path')->push($photo->path)->filter();
                $derivativePrefix = $this->derivativePrefix($photo->path);
                $prefixPaths = $this->filesystems->disk($photo->disk)->allFiles($derivativePrefix);

                foreach ($knownPaths->merge($prefixPaths)->unique() as $path) {
                    if (is_string($path)) {
                        $this->recordManifest($locked, $photo->disk, $path, 'photo', (string) $photo->id);
                    }
                }
            }

            $this->moveCursor($locked, (int) $photos->last()->id);
        }, attempts: 3);

        return false;
    }

    private function scanStagingChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        DB::transaction(function () use ($progress, $dispatchToken): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'scan_staging');

            if ($locked === null) {
                return;
            }

            $slots = PhotoGenerationSlot::query()
                ->whereHas('generationBatch', fn (Builder $query): Builder => $query->where('account_id', $locked->account_id))
                ->where('id', '>', $locked->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'staged_disk', 'staged_path', 'staging_prefix']);

            if ($slots->isEmpty()) {
                $this->advance($locked, 'cleanup_storage');

                return;
            }

            foreach ($slots as $slot) {
                $disk = $slot->staged_disk ?? (string) config('photostudio.disk');
                $paths = collect();

                if (is_string($slot->staged_path)) {
                    $paths->push($slot->staged_path);
                }

                foreach ($this->filesystems->disk($disk)->allFiles($this->validatedPrefix($slot->staging_prefix)) as $path) {
                    $paths->push($path);
                }

                foreach ($paths->unique() as $path) {
                    if (is_string($path)) {
                        $this->recordManifest($locked, $disk, $path, 'generation_slot', (string) $slot->id);
                    }
                }
            }

            $this->moveCursor($locked, (int) $slots->last()->id);
        }, attempts: 3);

        return false;
    }

    private function cleanupStorageChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        $cleanupIds = DB::transaction(function () use ($progress, $dispatchToken): array {
            $locked = $this->lockProgress($progress, $dispatchToken, 'cleanup_storage');

            if ($locked === null) {
                return [];
            }

            $cleanupIds = WorkspaceErasureObject::query()
                ->where('workspace_erasure_progress_id', $locked->id)
                ->whereHas('cleanup', fn (Builder $query): Builder => $query->whereNull('completed_at'))
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->pluck('photo_storage_cleanup_id')
                ->all();

            if ($cleanupIds === []) {
                $this->advance($locked, 'verify_storage', 'photos');
            } else {
                $this->heartbeat($locked);
            }

            return array_map(fn (mixed $id): int => (int) $id, $cleanupIds);
        }, attempts: 3);

        if ($cleanupIds !== []) {
            $this->cleanupService->deleteRecorded($cleanupIds);
        }

        return false;
    }

    private function verifyStorageChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        return match ($progress->checkpoint) {
            'photos' => $this->verifyPhotoChunk($progress, $dispatchToken),
            'slots' => $this->verifySlotChunk($progress, $dispatchToken),
            'objects' => $this->verifyObjectChunk($progress, $dispatchToken),
            default => throw WorkspaceErasureFailed::database(),
        };
    }

    private function verifyPhotoChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        DB::transaction(function () use ($progress, $dispatchToken): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'verify_storage', 'photos');

            if ($locked === null) {
                return;
            }

            $photos = Photo::query()
                ->where('account_id', $locked->account_id)
                ->where('id', '>', $locked->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'disk', 'path']);

            if ($photos->isEmpty()) {
                $this->checkpoint($locked, 'slots');

                return;
            }

            $found = false;

            foreach ($photos as $photo) {
                foreach ($this->filesystems->disk($photo->disk)->allFiles($this->derivativePrefix($photo->path)) as $path) {
                    $found = true;
                    $this->recordManifest($locked, $photo->disk, $path, 'photo_verify', (string) $photo->id);
                }
            }

            $found ? $this->advance($locked, 'cleanup_storage') : $this->moveCursor($locked, (int) $photos->last()->id);
        }, attempts: 3);

        return false;
    }

    private function verifySlotChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        DB::transaction(function () use ($progress, $dispatchToken): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'verify_storage', 'slots');

            if ($locked === null) {
                return;
            }

            $slots = PhotoGenerationSlot::query()
                ->whereHas('generationBatch', fn (Builder $query): Builder => $query->where('account_id', $locked->account_id))
                ->where('id', '>', $locked->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'staged_disk', 'staging_prefix']);

            if ($slots->isEmpty()) {
                $this->checkpoint($locked, 'objects');

                return;
            }

            $found = false;

            foreach ($slots as $slot) {
                $disk = $slot->staged_disk ?? (string) config('photostudio.disk');

                foreach ($this->filesystems->disk($disk)->allFiles($this->validatedPrefix($slot->staging_prefix)) as $path) {
                    $found = true;
                    $this->recordManifest($locked, $disk, $path, 'slot_verify', (string) $slot->id);
                }
            }

            $found ? $this->advance($locked, 'cleanup_storage') : $this->moveCursor($locked, (int) $slots->last()->id);
        }, attempts: 3);

        return false;
    }

    private function verifyObjectChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        DB::transaction(function () use ($progress, $dispatchToken): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'verify_storage', 'objects');

            if ($locked === null) {
                return;
            }

            if ($this->hasActiveWork($locked->account_id)) {
                $this->advance($locked, 'drain_work');

                return;
            }

            $objects = WorkspaceErasureObject::query()
                ->where('workspace_erasure_progress_id', $locked->id)
                ->where('id', '>', $locked->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get();

            if ($objects->isEmpty()) {
                $hasIncomplete = WorkspaceErasureObject::query()
                    ->where('workspace_erasure_progress_id', $locked->id)
                    ->whereHas('cleanup', fn (Builder $query): Builder => $query->whereNull('completed_at'))
                    ->exists();

                if ($hasIncomplete) {
                    $this->advance($locked, 'cleanup_storage');

                    return;
                }

                $locked->update(['storage_verified_at' => now()]);
                $this->advance($locked, 'purge_nonstorage', 'validation_runs');

                return;
            }

            foreach ($objects as $object) {
                if ($this->filesystems->disk($object->disk)->exists($object->path)) {
                    $cleanup = $this->cleanupService->record($object->disk, $object->path);
                    $object->update([
                        'photo_storage_cleanup_id' => $cleanup->id,
                        'verified_missing_at' => null,
                    ]);
                    $this->advance($locked, 'cleanup_storage');

                    return;
                }

                $object->update(['verified_missing_at' => now()]);
            }

            $this->moveCursor($locked, (int) $objects->last()->id);
        }, attempts: 3);

        return false;
    }

    private function purgeNonstorageChunk(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        return match ($progress->checkpoint) {
            'validation_runs' => $this->deleteIntegerChunk(
                $progress,
                $dispatchToken,
                fn (WorkspaceErasureProgress $locked): Builder => ValidationRun::query()->whereHas(
                    'business',
                    fn (Builder $query): Builder => $query->where('account_id', $locked->account_id),
                ),
                'conversations',
            ),
            'conversations' => $this->deleteUuidChunk(
                $progress,
                $dispatchToken,
                fn (WorkspaceErasureProgress $locked): Builder => AgentConversation::query()->where('account_id', $locked->account_id),
                'account_invitations',
            ),
            'account_invitations' => $this->deleteIntegerChunk(
                $progress,
                $dispatchToken,
                fn (WorkspaceErasureProgress $locked): Builder => AccountInvitation::query()->where('account_id', $locked->account_id),
                'project_invitations',
            ),
            'project_invitations' => $this->deleteIntegerChunk(
                $progress,
                $dispatchToken,
                fn (WorkspaceErasureProgress $locked): Builder => ProjectInvitation::query()->whereHas(
                    'project',
                    fn (Builder $query): Builder => $query->where('account_id', $locked->account_id),
                ),
                'leases',
            ),
            'leases' => $this->deleteUuidChunk(
                $progress,
                $dispatchToken,
                fn (WorkspaceErasureProgress $locked): Builder => PhotoOperationLease::query()->where('account_id', $locked->account_id),
                'finish',
            ),
            'finish' => $this->advanceToDeleteAccount($progress, $dispatchToken),
            default => throw WorkspaceErasureFailed::database(),
        };
    }

    /**
     * @template TModel of Model
     *
     * @param  callable(WorkspaceErasureProgress): Builder<TModel>  $query
     */
    private function deleteIntegerChunk(
        WorkspaceErasureProgress $progress,
        string $dispatchToken,
        callable $query,
        string $nextCheckpoint,
    ): bool {
        DB::transaction(function () use ($progress, $dispatchToken, $query, $nextCheckpoint): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'purge_nonstorage', $progress->checkpoint);

            if ($locked === null) {
                return;
            }

            $rows = $query($locked)
                ->where('id', '>', $locked->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id']);

            if ($rows->isEmpty()) {
                $this->checkpoint($locked, $nextCheckpoint);

                return;
            }

            $query($locked)->whereKey($rows->modelKeys())->delete();
            $this->moveCursor($locked, (int) $rows->last()->getKey());
        }, attempts: 3);

        return false;
    }

    /**
     * @template TModel of Model
     *
     * @param  callable(WorkspaceErasureProgress): Builder<TModel>  $query
     */
    private function deleteUuidChunk(
        WorkspaceErasureProgress $progress,
        string $dispatchToken,
        callable $query,
        string $nextCheckpoint,
    ): bool {
        DB::transaction(function () use ($progress, $dispatchToken, $query, $nextCheckpoint): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'purge_nonstorage', $progress->checkpoint);

            if ($locked === null) {
                return;
            }

            $rows = $query($locked)->orderBy('id')->limit($this->chunkSize())->lockForUpdate()->get(['id']);

            if ($rows->isEmpty()) {
                $this->checkpoint($locked, $nextCheckpoint);

                return;
            }

            $query($locked)->whereKey($rows->modelKeys())->delete();
            $this->heartbeat($locked);
        }, attempts: 3);

        return false;
    }

    private function advanceToDeleteAccount(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        DB::transaction(function () use ($progress, $dispatchToken): void {
            $locked = $this->lockProgress($progress, $dispatchToken, 'purge_nonstorage', 'finish');

            if ($locked !== null) {
                $this->advance($locked, 'teardown_billing');
            }
        }, attempts: 3);

        return false;
    }

    private function teardownBilling(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        $stripeCustomerId = DB::transaction(function () use ($progress, $dispatchToken): ?string {
            $account = Account::query()->lockForUpdate()->find($progress->account_id);
            $locked = $this->lockProgress($progress, $dispatchToken, 'teardown_billing');

            if ($locked === null || ! $account instanceof Account) {
                throw WorkspaceErasureFailed::database();
            }

            if ($locked->billing_teardown_completed_at !== null) {
                $this->advance($locked, 'delete_account');

                return null;
            }

            if (is_string($account->billing_checkout_token)
                && $account->billing_checkout_session_id === null
                && $account->billing_checkout_expires_at?->isFuture() === true) {
                throw WorkspaceErasureFailed::database();
            }

            $customerId = $locked->billing_customer_id ?? $account->stripe_id;

            if (! is_string($customerId) || $customerId === '') {
                $locked->update([
                    'billing_customer_id' => null,
                    'billing_teardown_proof' => $this->stripeCustomerTeardown->missingCustomerProof(),
                    'billing_teardown_completed_at' => now(),
                ]);
                $this->advance($locked, 'delete_account');

                return null;
            }

            $locked->update([
                'billing_customer_id' => $customerId,
                'last_progress_at' => now(),
                'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
            ]);

            return $customerId;
        }, attempts: 3);

        if ($stripeCustomerId === null) {
            return false;
        }

        $proof = $this->stripeCustomerTeardown->delete($stripeCustomerId);

        DB::transaction(function () use ($progress, $dispatchToken, $stripeCustomerId, $proof): void {
            $account = Account::query()->lockForUpdate()->find($progress->account_id);
            $locked = $this->lockProgress($progress, $dispatchToken, 'teardown_billing');

            if ($locked === null
                || ! $account instanceof Account
                || ! is_string($locked->billing_customer_id)
                || ! hash_equals($locked->billing_customer_id, $stripeCustomerId)
                || (is_string($account->stripe_id) && ! hash_equals($account->stripe_id, $stripeCustomerId))) {
                throw WorkspaceErasureFailed::database();
            }

            $account->forceFill([
                'stripe_id' => null,
                'pm_type' => null,
                'pm_last_four' => null,
                'trial_ends_at' => null,
                'billing_checkout_token' => null,
                'billing_checkout_session_id' => null,
                'billing_checkout_expires_at' => null,
                'billing_checkout_status' => null,
                'billing_checkout_interval' => null,
                'billing_checkout_price_fingerprint' => null,
            ])->save();
            $locked->update([
                'billing_customer_id' => null,
                'billing_teardown_proof' => $proof,
                'billing_teardown_completed_at' => now(),
            ]);
            $this->advance($locked, 'delete_account');
        }, attempts: 3);

        return false;
    }

    private function deleteAccount(WorkspaceErasureProgress $progress, string $dispatchToken): bool
    {
        return DB::transaction(function () use ($progress, $dispatchToken): bool {
            $account = Account::query()->lockForUpdate()->find($progress->account_id);
            $locked = $this->lockProgress($progress, $dispatchToken, 'delete_account');

            if ($locked === null) {
                return false;
            }

            if ($account === null) {
                $incompleteCleanup = WorkspaceErasureObject::query()
                    ->where('workspace_erasure_progress_id', $locked->id)
                    ->where(fn (Builder $query): Builder => $query
                        ->whereNull('verified_missing_at')
                        ->orWhereHas('cleanup', fn (Builder $cleanup): Builder => $cleanup->whereNull('completed_at')))
                    ->exists();

                if ($locked->storage_verified_at === null
                    || $locked->billing_teardown_completed_at === null
                    || ! is_string($locked->billing_teardown_proof)
                    || $incompleteCleanup) {
                    throw WorkspaceErasureFailed::database();
                }

                $locked->update([
                    'phase' => 'completed',
                    'checkpoint' => 'primary',
                    'completed_at' => now(),
                    'claimed_at' => null,
                    'claim_expires_at' => null,
                    'enqueued_at' => null,
                    'last_progress_at' => now(),
                ]);

                return true;
            }

            $incompleteCleanup = WorkspaceErasureObject::query()
                ->where('workspace_erasure_progress_id', $locked->id)
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('verified_missing_at')
                    ->orWhereHas('cleanup', fn (Builder $cleanup): Builder => $cleanup->whereNull('completed_at')))
                ->exists();
            $projectIds = Project::query()->where('account_id', $account->id)->select('id');

            if ($account->erasure_started_at === null
                || $locked->storage_verified_at === null
                || $locked->billing_teardown_completed_at === null
                || ! is_string($locked->billing_teardown_proof)
                || $incompleteCleanup
                || $this->hasActiveWork($account->id)
                || User::query()->where('current_account_id', $account->id)->exists()
                || DB::table('account_user')->where('account_id', $account->id)->exists()
                || AccountInvitation::query()->where('account_id', $account->id)->exists()
                || DB::table('project_user')->whereIn('project_id', $projectIds)->exists()
                || ProjectInvitation::query()->whereIn('project_id', $projectIds)->exists()
            ) {
                throw WorkspaceErasureFailed::database();
            }

            $auditCount = AiOperationAudit::query()->where('account_id', $account->id)->count();
            WorkspaceErasureObject::query()->where('workspace_erasure_progress_id', $locked->id)->delete();
            $account->delete();

            if (AiOperationAudit::query()->where('account_id', $locked->account_id)->count() !== $auditCount) {
                throw WorkspaceErasureFailed::database();
            }

            $locked->increment('revision', 1, [
                'phase' => 'completed',
                'checkpoint' => 'primary',
                'cursor' => 0,
                'completed_at' => now(),
                'claimed_at' => null,
                'claim_expires_at' => null,
                'enqueued_at' => null,
                'last_progress_at' => now(),
                'last_error_code' => null,
            ]);

            return true;
        }, attempts: 3);
    }

    private function recordManifest(
        WorkspaceErasureProgress $progress,
        string $disk,
        string $path,
        string $sourceType,
        string $sourceId,
    ): void {
        $this->assertSafeObject($disk, $path);
        $cleanup = $this->cleanupService->record($disk, $path);

        WorkspaceErasureObject::query()->updateOrCreate(
            [
                'workspace_erasure_progress_id' => $progress->id,
                'disk' => $disk,
                'path_hash' => hash('sha256', $path),
            ],
            [
                'photo_storage_cleanup_id' => $cleanup->id,
                'path' => $path,
                'source_type' => Str::limit($sourceType, 40, ''),
                'source_id' => Str::limit($sourceId, 191, ''),
                'verified_missing_at' => null,
            ],
        );
    }

    private function assertSafeObject(string $disk, string $path): void
    {
        if ($disk !== (string) config('photostudio.disk')
            || $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '..')
            || (! str_starts_with($path, (string) config('photostudio.uploaded_prefix'))
                && ! str_starts_with($path, (string) config('photostudio.generated_prefix')))
        ) {
            throw WorkspaceErasureFailed::storage();
        }
    }

    private function validatedPrefix(string $prefix): string
    {
        $prefix = rtrim($prefix, '/').'/';
        $this->assertSafeObject((string) config('photostudio.disk'), $prefix.'placeholder');

        return $prefix;
    }

    private function derivativePrefix(string $path): string
    {
        $directory = dirname($path);
        $prefix = ($directory === '.' ? pathinfo($path, PATHINFO_FILENAME) : $directory).'/derivatives/';

        return $this->validatedPrefix($prefix);
    }

    private function hasActiveWork(int $accountId): bool
    {
        return AiOperationAudit::query()
            ->where('account_id', $accountId)
            ->where('event', AiAuditEvent::Started->value)
            ->where('occurred_at', '>=', now()->subMinutes(15))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ai_operation_audits as completion')
                    ->whereColumn('completion.operation_id', 'ai_operation_audits.operation_id')
                    ->whereColumn('completion.account_id', 'ai_operation_audits.account_id')
                    ->whereIn('completion.event', [AiAuditEvent::Succeeded->value, AiAuditEvent::Failed->value]);
            })
            ->exists()
            || PhotoOperationLease::query()
                ->where('account_id', $accountId)
                ->whereNull('finished_at')
                ->where('expires_at', '>', now())
                ->exists()
            || PhotoGenerationBatch::query()
                ->where('account_id', $accountId)
                ->whereIn('analysis_state', ['claimed', 'provider_started'])
                ->where('analysis_claim_expires_at', '>', now())
                ->exists()
            || Photo::query()
                ->where('account_id', $accountId)
                ->whereIn('description_state', ['claimed', 'provider_started'])
                ->where('description_claim_expires_at', '>', now())
                ->exists()
            || PhotoGenerationSlot::query()
                ->whereHas('generationBatch', fn (Builder $query): Builder => $query->where('account_id', $accountId))
                ->whereIn('status', ['claimed', 'provider_started'])
                ->where('claim_expires_at', '>', now())
                ->exists();
    }

    private function lockProgress(
        WorkspaceErasureProgress $progress,
        string $dispatchToken,
        string $phase,
        ?string $checkpoint = null,
    ): ?WorkspaceErasureProgress {
        $locked = WorkspaceErasureProgress::query()->lockForUpdate()->find($progress->id);

        if ($locked === null
            || $locked->phase !== $phase
            || ($checkpoint !== null && $locked->checkpoint !== $checkpoint)
            || ! is_string($locked->dispatch_token)
            || ! hash_equals($locked->dispatch_token, $dispatchToken)
        ) {
            return null;
        }

        return $locked;
    }

    private function moveCursor(WorkspaceErasureProgress $progress, int $cursor): void
    {
        $progress->increment('revision', 1, [
            'cursor' => $cursor,
            'last_progress_at' => now(),
            'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
        ]);
    }

    private function checkpoint(WorkspaceErasureProgress $progress, string $checkpoint): void
    {
        $progress->increment('revision', 1, [
            'checkpoint' => $checkpoint,
            'cursor' => 0,
            'last_progress_at' => now(),
            'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
        ]);
    }

    private function advance(WorkspaceErasureProgress $progress, string $phase, string $checkpoint = 'primary'): void
    {
        $progress->increment('revision', 1, [
            'phase' => $phase,
            'checkpoint' => $checkpoint,
            'cursor' => 0,
            'last_progress_at' => now(),
            'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
        ]);
    }

    private function heartbeat(WorkspaceErasureProgress $progress): void
    {
        $progress->increment('revision', 1, [
            'last_progress_at' => now(),
            'claim_expires_at' => now()->addSeconds($this->claimSeconds()),
        ]);
    }

    private function chunkSize(): int
    {
        return max(1, min(500, (int) config('photostudio.workspace_erasure_chunk_size', 50)));
    }

    private function claimSeconds(): int
    {
        return max(60, (int) config('photostudio.lifecycle.claim_seconds', 600));
    }
}
