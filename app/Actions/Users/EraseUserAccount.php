<?php

namespace App\Actions\Users;

use App\Actions\Accounts\StartWorkspaceErasure;
use App\Exceptions\AccountErasureFailed;
use App\Jobs\EraseUserAccountData;
use App\Models\Account;
use App\Models\AccountErasureProgress;
use App\Models\AccountErasureTarget;
use App\Models\AccountInvitation;
use App\Models\AdvertisingKit;
use App\Models\AgentConversation;
use App\Models\AgentConversationMessage;
use App\Models\BrandKit;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\PhotoOperationLease;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Models\UserFeedback;
use App\Models\ValidationRun;
use App\Models\WorkspaceErasureProgress;
use App\Services\LifecycleRuntime;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoStorageCleanupService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class EraseUserAccount
{
    private const string AccountTarget = 'account';

    private const string BatchTarget = 'photo_generation_batch';

    private const string ScopeSnapshotTarget = 'scope_snapshot';

    private const int ScopeSnapshotAttempts = 3;

    public function __construct(
        private PhotoGenerationLifecycle $photoGenerationLifecycle,
        private PhotoStorageCleanupService $cleanupService,
        private LifecycleRuntime $runtime,
        private StartWorkspaceErasure $startWorkspaceErasure,
    ) {}

    /**
     * Atomically mark an account and enqueue its durable database-backed
     * erasure. The database progress row is the recovery source of truth.
     *
     * @throws AccountErasureFailed
     * @throws AuthorizationException
     */
    public function handle(User $user): void
    {
        $this->authorize($user);

        try {
            $this->runtime->assertReady();

            $this->startErasure($user);
        } catch (AccountErasureFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $user->refresh();

            Log::error('Account erasure could not be started.', [
                'user_id' => $user->id,
                'exception_class' => $exception::class,
            ]);

            throw AccountErasureFailed::database();
        }
    }

    /**
     * Process one bounded, idempotent chunk of the account erasure.
     */
    public function resume(int $userId): bool
    {
        $user = User::query()->find($userId);

        if ($user === null || $user->account_erasure_started_at === null) {
            return true;
        }

        if (! $this->scopeWasSnapshotted($user)) {
            throw AccountErasureFailed::database();
        }

        if ($this->photoGenerationLifecycle->hasActiveLeases($user)) {
            return false;
        }

        $progress = AccountErasureProgress::query()->firstOrCreate(['user_id' => $user->id]);

        try {
            return match ($progress->phase) {
                'scan_batches' => $this->scanBatchChunk($user, $progress),
                'photos' => $this->erasePhotoChunk($user, $progress),
                'generation_slots' => $this->eraseGenerationSlotChunk($user, $progress),
                'batches' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => $this->batchesErasedWith($user),
                    'advisor_messages',
                ),
                'advisor_messages' => $this->advanceOnly($progress, 'advisor_conversations'),
                'advisor_conversations' => $this->deleteUuidChunk(
                    $progress,
                    fn (): Builder => $this->conversationsErasedWith($user),
                    'validation_runs',
                ),
                'validation_runs' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => $this->validationRunsErasedWith($user),
                    'advertising_kits',
                ),
                'advertising_kits' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => AdvertisingKit::query()->whereHas(
                        'business',
                        fn (Builder $business): Builder => $business->whereIn('account_id', $this->targetAccountIds($user)),
                    ),
                    'brand_kits',
                ),
                'brand_kits' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => BrandKit::query()->whereHas(
                        'business',
                        fn (Builder $business): Builder => $business->whereIn('account_id', $this->targetAccountIds($user)),
                    ),
                    'feedback',
                ),
                'feedback' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => UserFeedback::query()->where('user_id', $user->id),
                    'account_invitations',
                ),
                'account_invitations' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => $this->accountInvitationsErasedWith($user),
                    'invitations',
                ),
                'invitations' => $this->deleteIntegerChunk(
                    $progress,
                    fn (): Builder => $this->projectInvitationsErasedWith($user),
                    'project_memberships',
                ),
                'project_memberships' => $this->eraseProjectMembershipChunk($user, $progress),
                'account_memberships' => $this->eraseAccountMembershipChunk($user, $progress),
                'projects' => $this->advanceOnly($progress, 'businesses'),
                'businesses' => $this->advanceOnly($progress, 'authentication'),
                'authentication' => $this->eraseAuthenticationRecords($user, $progress),
                'operation_leases' => $this->eraseOperationLeaseChunk($user, $progress),
                'storage_cleanup' => $this->finishStorageCleanup($progress),
                'finish' => $this->finish($user, $progress),
                'workspace_erasure' => $this->awaitWorkspaceErasure($user, $progress),
                default => throw AccountErasureFailed::database(),
            };
        } catch (AccountErasureFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::critical('Account erasure chunk failed.', [
                'user_id' => $user->id,
                'phase' => $progress->phase,
                'exception_class' => $exception::class,
            ]);

            throw AccountErasureFailed::database();
        }
    }

    private function scanBatchChunk(User $user, AccountErasureProgress $progress): bool
    {
        DB::transaction(function () use ($user, $progress): void {
            $lockedProgress = $this->lockProgress($progress, 'scan_batches');

            if ($lockedProgress === null) {
                return;
            }

            $batches = PhotoGenerationBatch::query()
                ->where('id', '>', $lockedProgress->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'account_id', 'project_id', 'user_id', 'input_photo_ids']);

            if ($batches->isEmpty()) {
                $this->advance($lockedProgress, 'photos');

                return;
            }

            $inputPhotoIds = $batches->flatMap(fn (PhotoGenerationBatch $batch): array => $batch->input_photo_ids ?? [])->unique();
            $ownedInputIds = Photo::query()
                ->whereKey($inputPhotoIds)
                ->where('user_id', $user->id)
                ->pluck('id');
            $targetAccountIds = $this->targetAccountIds($user)->pluck('resource_id');

            $targets = $batches
                ->filter(fn (PhotoGenerationBatch $batch): bool => $batch->user_id === $user->id
                    || $targetAccountIds->contains($batch->account_id)
                    || $ownedInputIds->intersect($batch->input_photo_ids ?? [])->isNotEmpty())
                ->map(fn (PhotoGenerationBatch $batch): array => [
                    'user_id' => $user->id,
                    'resource_type' => self::BatchTarget,
                    'resource_id' => $batch->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->all();

            if ($targets !== []) {
                AccountErasureTarget::query()->upsert(
                    $targets,
                    ['user_id', 'resource_type', 'resource_id'],
                    ['updated_at'],
                );
            }

            $this->moveCursor($lockedProgress, (int) $batches->last()->id);
        }, attempts: 3);

        return false;
    }

    private function erasePhotoChunk(User $user, AccountErasureProgress $progress): bool
    {
        $cleanupIds = DB::transaction(function () use ($user, $progress): array {
            $lockedProgress = $this->lockProgress($progress, 'photos');

            if ($lockedProgress === null) {
                return [];
            }

            $photos = $this->photosErasedWith($user)
                ->where('id', '>', $lockedProgress->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'disk', 'path', 'derivatives']);

            if ($photos->isEmpty()) {
                $this->advance($lockedProgress, 'generation_slots');

                return [];
            }

            $cleanupIds = [];

            foreach ($photos as $photo) {
                $paths = collect($photo->derivatives ?? [])->pluck('path')->push($photo->path)->filter()->all();
                $cleanupIds = [
                    ...$cleanupIds,
                    ...$this->cleanupService->recordMany($photo->disk, $paths)->pluck('id')->all(),
                ];
            }

            Photo::query()->whereKey($photos->modelKeys())->delete();

            DB::table('account_erasure_cleanup')->insertOrIgnore(
                collect($cleanupIds)->unique()->map(fn (int $cleanupId): array => [
                    'account_erasure_progress_id' => $lockedProgress->id,
                    'photo_storage_cleanup_id' => $cleanupId,
                ])->all(),
            );

            $this->moveCursor($lockedProgress, (int) $photos->last()->id);

            return array_values(array_unique($cleanupIds));
        }, attempts: 3);

        if ($cleanupIds !== []) {
            $this->cleanupService->deleteRecorded($cleanupIds);
        }

        return false;
    }

    private function eraseGenerationSlotChunk(User $user, AccountErasureProgress $progress): bool
    {
        $cleanupIds = DB::transaction(function () use ($user, $progress): array {
            $lockedProgress = $this->lockProgress($progress, 'generation_slots');

            if ($lockedProgress === null) {
                return [];
            }

            $batchIds = $this->batchesErasedWith($user)->select('id');
            $slots = PhotoGenerationSlot::query()
                ->whereIn('photo_generation_batch_id', $batchIds)
                ->where('id', '>', $lockedProgress->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id', 'staged_disk', 'staged_path']);

            if ($slots->isEmpty()) {
                $this->advance($lockedProgress, 'batches');

                return [];
            }

            $cleanupIds = [];

            foreach ($slots as $slot) {
                if (is_string($slot->staged_disk) && is_string($slot->staged_path)) {
                    $cleanupIds = [
                        ...$cleanupIds,
                        ...$this->cleanupService->recordMany($slot->staged_disk, [$slot->staged_path])->pluck('id')->all(),
                    ];
                }
            }

            PhotoGenerationSlot::query()->whereKey($slots->modelKeys())->delete();

            DB::table('account_erasure_cleanup')->insertOrIgnore(
                collect($cleanupIds)->unique()->map(fn (int $cleanupId): array => [
                    'account_erasure_progress_id' => $lockedProgress->id,
                    'photo_storage_cleanup_id' => $cleanupId,
                ])->all(),
            );

            $this->moveCursor($lockedProgress, (int) $slots->last()->id);

            return array_values(array_unique($cleanupIds));
        }, attempts: 3);

        if ($cleanupIds !== []) {
            $this->cleanupService->deleteRecorded($cleanupIds);
        }

        return false;
    }

    /**
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $query
     */
    private function deleteIntegerChunk(
        AccountErasureProgress $progress,
        Closure $query,
        string $nextPhase,
    ): bool {
        DB::transaction(function () use ($progress, $query, $nextPhase): void {
            $lockedProgress = $this->lockProgress($progress, $progress->phase);

            if ($lockedProgress === null) {
                return;
            }

            $rows = $query()
                ->where('id', '>', $lockedProgress->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->get(['id']);

            if ($rows->isEmpty()) {
                $this->advance($lockedProgress, $nextPhase);

                return;
            }

            $query()->whereKey($rows->modelKeys())->delete();

            $lastKey = $rows->last()->getKey();

            if (! is_int($lastKey)) {
                throw AccountErasureFailed::database();
            }

            $this->moveCursor($lockedProgress, $lastKey);
        }, attempts: 3);

        return false;
    }

    /**
     * @template TModel of Model
     *
     * @param  Closure(): Builder<TModel>  $query
     */
    private function deleteUuidChunk(
        AccountErasureProgress $progress,
        Closure $query,
        string $nextPhase,
    ): bool {
        DB::transaction(function () use ($progress, $query, $nextPhase): void {
            $lockedProgress = $this->lockProgress($progress, $progress->phase);

            if ($lockedProgress === null) {
                return;
            }

            $rows = $query()->orderBy('id')->limit($this->chunkSize())->lockForUpdate()->get(['id']);

            if ($rows->isEmpty()) {
                $this->advance($lockedProgress, $nextPhase);

                return;
            }

            $query()->whereKey($rows->modelKeys())->delete();
            $this->heartbeat($lockedProgress);
        }, attempts: 3);

        return false;
    }

    private function eraseProjectMembershipChunk(User $user, AccountErasureProgress $progress): bool
    {
        DB::transaction(function () use ($user, $progress): void {
            $lockedProgress = $this->lockProgress($progress, 'project_memberships');

            if ($lockedProgress === null) {
                return;
            }

            $ids = DB::table('project_user')
                ->where('user_id', $user->id)
                ->where('id', '>', $lockedProgress->cursor)
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                $this->advance($lockedProgress, 'account_memberships');

                return;
            }

            DB::table('project_user')->whereIn('id', $ids)->delete();
            $this->moveCursor($lockedProgress, (int) $ids->last());
        }, attempts: 3);

        return false;
    }

    private function eraseAuthenticationRecords(User $user, AccountErasureProgress $progress): bool
    {
        DB::transaction(function () use ($user, $progress): void {
            $lockedProgress = $this->lockProgress($progress, 'authentication');

            if ($lockedProgress === null) {
                return;
            }

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();

            $broker = config('auth.defaults.passwords', 'users');
            $passwordResetTable = config("auth.passwords.{$broker}.table", 'password_reset_tokens');
            DB::table($passwordResetTable)->where('email', $user->email)->delete();

            $this->advance($lockedProgress, 'operation_leases');
        }, attempts: 3);

        return false;
    }

    private function eraseAccountMembershipChunk(User $user, AccountErasureProgress $progress): bool
    {
        DB::transaction(function () use ($user, $progress): void {
            $lockedProgress = $this->lockProgress($progress, 'account_memberships');

            if ($lockedProgress === null) {
                return;
            }

            $accountIds = DB::table('account_user')
                ->where('user_id', $user->id)
                ->where('role', '!=', 'owner')
                ->where('account_id', '>', $lockedProgress->cursor)
                ->orderBy('account_id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->pluck('account_id');

            if ($accountIds->isEmpty()) {
                $this->advance($lockedProgress, 'projects');

                return;
            }

            DB::table('account_user')
                ->where('user_id', $user->id)
                ->whereIn('account_id', $accountIds)
                ->delete();
            $this->moveCursor($lockedProgress, (int) $accountIds->last());
        }, attempts: 3);

        return false;
    }

    private function eraseOperationLeaseChunk(User $user, AccountErasureProgress $progress): bool
    {
        DB::transaction(function () use ($user, $progress): void {
            $lockedProgress = $this->lockProgress($progress, 'operation_leases');

            if ($lockedProgress === null) {
                return;
            }

            $ids = PhotoOperationLease::query()
                ->where(fn (Builder $query): Builder => $query
                    ->where('initiating_user_id', $user->id)
                    ->orWhereJsonContains('protected_user_ids', $user->id))
                ->orderBy('id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                $this->advance($lockedProgress, 'storage_cleanup');

                return;
            }

            PhotoOperationLease::query()->whereKey($ids)->delete();
            $this->heartbeat($lockedProgress);
        }, attempts: 3);

        return false;
    }

    private function finishStorageCleanup(AccountErasureProgress $progress): bool
    {
        $cleanupIds = DB::transaction(function () use ($progress): array {
            $lockedProgress = $this->lockProgress($progress, 'storage_cleanup');

            if ($lockedProgress === null) {
                return [];
            }

            $cleanupIds = DB::table('account_erasure_cleanup')
                ->join(
                    'photo_storage_cleanups',
                    'photo_storage_cleanups.id',
                    '=',
                    'account_erasure_cleanup.photo_storage_cleanup_id',
                )
                ->where('account_erasure_cleanup.account_erasure_progress_id', $lockedProgress->id)
                ->whereNull('photo_storage_cleanups.completed_at')
                ->orderBy('photo_storage_cleanups.id')
                ->limit($this->chunkSize())
                ->lockForUpdate()
                ->pluck('photo_storage_cleanups.id')
                ->all();

            if ($cleanupIds === []) {
                $this->advance($lockedProgress, 'finish');
            } else {
                $this->heartbeat($lockedProgress);
            }

            return $cleanupIds;
        }, attempts: 3);

        if ($cleanupIds !== []) {
            $this->cleanupService->deleteRecorded($cleanupIds);
        }

        return false;
    }

    private function finish(User $user, AccountErasureProgress $progress): bool
    {
        return DB::transaction(function () use ($user, $progress): bool {
            $lockedProgress = $this->lockProgress($progress, 'finish');
            $lockedUser = User::query()->lockForUpdate()->find($user->id);

            if ($lockedProgress === null || $lockedUser === null) {
                return $lockedUser === null;
            }

            $targetAccounts = Account::query()
                ->whereIn('id', $this->targetAccountIds($lockedUser))
                ->lockForUpdate()
                ->get();

            if ($targetAccounts->isNotEmpty()) {
                $this->confirmTargetOwnership($lockedUser, $targetAccounts->modelKeys());
                $this->advance($lockedProgress, 'workspace_erasure');

                return false;
            }

            if (Account::query()->whereHas('members', fn (Builder $query): Builder => $query
                ->whereKey($lockedUser->id)
                ->where('account_user.role', 'owner'))->exists()) {
                throw AccountErasureFailed::ownershipTransferRequired();
            }

            if ($this->rewindForLateReferences($lockedUser, $lockedProgress)) {
                return false;
            }

            $this->assertRestrictiveReferencesRemoved($lockedUser);
            $lockedUser->setRememberToken('');
            $lockedUser->delete();

            return true;
        }, attempts: 3);
    }

    private function awaitWorkspaceErasure(User $user, AccountErasureProgress $progress): bool
    {
        $targetAccountIds = $this->targetAccountIds($user)
            ->orderBy('resource_id')
            ->pluck('resource_id')
            ->map(fn (mixed $accountId): int => (int) $accountId);

        foreach ($targetAccountIds as $accountId) {
            if (Account::query()->whereKey($accountId)->exists()) {
                $this->startWorkspaceErasure->handleForUserErasure($accountId, $user->id);
            }
        }

        $completedAccountIds = WorkspaceErasureProgress::query()
            ->whereIn('account_id', $targetAccountIds)
            ->whereNotNull('completed_at')
            ->pluck('account_id')
            ->map(fn (mixed $accountId): int => (int) $accountId);

        if ($completedAccountIds->sort()->values()->all() !== $targetAccountIds->sort()->values()->all()) {
            return false;
        }

        DB::transaction(function () use ($user, $progress): void {
            $lockedProgress = $this->lockProgress($progress, 'workspace_erasure');

            if ($lockedProgress === null) {
                return;
            }

            $targetAccounts = Account::query()
                ->whereIn('id', $this->targetAccountIds($user))
                ->lockForUpdate()
                ->get();

            if ($targetAccounts->isNotEmpty()) {
                $this->confirmTargetOwnership($user, $targetAccounts->modelKeys());

                return;
            }

            $this->advance($lockedProgress, 'finish');
        }, attempts: 3);

        return false;
    }

    /** @return Builder<Photo> */
    private function photosErasedWith(User $user): Builder
    {
        $targetBatchIds = AccountErasureTarget::query()
            ->select('resource_id')
            ->where('user_id', $user->id)
            ->where('resource_type', self::BatchTarget);

        return Photo::query()->where(fn (Builder $query): Builder => $query
            ->whereIn('account_id', $this->targetAccountIds($user))
            ->orWhere('user_id', $user->id)
            ->orWhereIn('photo_generation_batch_id', $targetBatchIds));
    }

    /** @return Builder<PhotoGenerationBatch> */
    private function batchesErasedWith(User $user): Builder
    {
        $targetBatchIds = AccountErasureTarget::query()
            ->select('resource_id')
            ->where('user_id', $user->id)
            ->where('resource_type', self::BatchTarget);

        return PhotoGenerationBatch::query()->where(fn (Builder $query): Builder => $query
            ->whereIn('account_id', $this->targetAccountIds($user))
            ->orWhere('user_id', $user->id)
            ->orWhereIn('id', $targetBatchIds));
    }

    /** @return Builder<AgentConversation> */
    private function conversationsErasedWith(User $user): Builder
    {
        return AgentConversation::query()->where(fn (Builder $query): Builder => $query
            ->whereIn('account_id', $this->targetAccountIds($user))
            ->orWhere('user_id', $user->id)
            ->orWhereHas('messages', fn (Builder $messages): Builder => $messages->where('user_id', $user->id)));
    }

    /** @return Builder<ValidationRun> */
    private function validationRunsErasedWith(User $user): Builder
    {
        return ValidationRun::query()->where(fn (Builder $query): Builder => $query
            ->whereHas('business', fn (Builder $business): Builder => $business->whereIn('account_id', $this->targetAccountIds($user)))
            ->orWhere(fn (Builder $personal): Builder => $personal
                ->where('user_id', $user->id)
                ->whereNull('business_id')));
    }

    /** @return Builder<AccountInvitation> */
    private function accountInvitationsErasedWith(User $user): Builder
    {
        return AccountInvitation::query()->where(
            fn (Builder $query): Builder => $query
                ->where('email', AccountInvitation::normalizeEmail($user->email))
                ->orWhere('accepted_by_user_id', $user->id),
        );
    }

    /** @return Builder<ProjectInvitation> */
    private function projectInvitationsErasedWith(User $user): Builder
    {
        return ProjectInvitation::query()->where(
            fn (Builder $query): Builder => $query
                ->where('email', ProjectInvitation::normalizeEmail($user->email))
                ->orWhere('accepted_by_user_id', $user->id),
        );
    }

    /** @return Builder<AccountErasureTarget> */
    private function targetAccountIds(User $user): Builder
    {
        return AccountErasureTarget::query()
            ->select('resource_id')
            ->where('user_id', $user->id)
            ->where('resource_type', self::AccountTarget);
    }

    private function startErasure(User $user): void
    {
        for ($attempt = 0; $attempt < self::ScopeSnapshotAttempts; $attempt++) {
            $candidateScope = $this->discoverAccountScope($user);

            $scopeWasStable = DB::transaction(function () use ($user, $candidateScope): bool {
                $lockedAccountIds = Account::query()
                    ->whereKey($candidateScope['all'])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn (mixed $accountId): int => (int) $accountId)
                    ->all();

                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

                if ($candidateScope !== $this->discoverAccountScope($lockedUser)) {
                    return false;
                }

                $memberships = DB::table('account_user')
                    ->whereIn('account_id', $candidateScope['all'])
                    ->orderBy('account_id')
                    ->orderBy('user_id')
                    ->lockForUpdate()
                    ->get(['account_id', 'user_id', 'role']);
                $lockedOwnedAccountIds = $memberships
                    ->filter(fn (object $membership): bool => (int) $membership->user_id === $lockedUser->id
                        && $membership->role === 'owner')
                    ->pluck('account_id')
                    ->map(fn (mixed $accountId): int => (int) $accountId)
                    ->sort()
                    ->values()
                    ->all();

                if ($lockedOwnedAccountIds !== $candidateScope['owned']) {
                    return false;
                }

                if (! $this->scopeWasSnapshotted($lockedUser)) {
                    $this->snapshotAccountScope($lockedUser, $memberships, $lockedOwnedAccountIds);
                } else {
                    $existingTargetAccountIds = array_values(array_intersect(
                        $candidateScope['targets'],
                        $lockedAccountIds,
                    ));

                    if ($existingTargetAccountIds !== $lockedOwnedAccountIds) {
                        throw AccountErasureFailed::ownershipTransferRequired();
                    }

                    $this->assertSoleOwnedAccounts($lockedUser, $memberships, $lockedOwnedAccountIds);
                }

                $startedNow = $this->photoGenerationLifecycle->beginAccountErasure($user);
                $progress = AccountErasureProgress::query()->firstOrCreate(['user_id' => $user->id]);

                if ((! $startedNow && ! $progress->wasRecentlyCreated) || $progress->enqueued_at !== null) {
                    return true;
                }

                $progress->update(['enqueued_at' => now()]);
                $this->runtime->dispatch(new EraseUserAccountData($user->id), $this->runtime->securityQueue());

                return true;
            }, attempts: 3);

            if ($scopeWasStable) {
                return;
            }
        }

        throw AccountErasureFailed::database();
    }

    /** @return array{owned: array<int, int>, targets: array<int, int>, all: array<int, int>} */
    private function discoverAccountScope(User $user): array
    {
        $ownedAccountIds = DB::table('account_user')
            ->where('user_id', $user->id)
            ->where('role', 'owner')
            ->orderBy('account_id')
            ->pluck('account_id')
            ->map(fn (mixed $accountId): int => (int) $accountId)
            ->all();

        $targetAccountIds = AccountErasureTarget::query()
            ->where('user_id', $user->id)
            ->where('resource_type', self::AccountTarget)
            ->orderBy('resource_id')
            ->pluck('resource_id')
            ->map(fn (mixed $accountId): int => (int) $accountId)
            ->all();

        $allAccountIds = collect($ownedAccountIds)
            ->merge($targetAccountIds)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'owned' => $ownedAccountIds,
            'targets' => $targetAccountIds,
            'all' => $allAccountIds,
        ];
    }

    /**
     * @param  Collection<int, \stdClass>  $memberships
     * @param  array<int, int>  $ownedAccountIds
     */
    private function snapshotAccountScope(User $user, Collection $memberships, array $ownedAccountIds): void
    {
        $this->assertSoleOwnedAccounts($user, $memberships, $ownedAccountIds);

        AccountErasureTarget::query()
            ->where('user_id', $user->id)
            ->where('resource_type', self::AccountTarget)
            ->when(
                $ownedAccountIds !== [],
                fn (Builder $query): Builder => $query->whereNotIn('resource_id', $ownedAccountIds),
            )
            ->delete();

        $now = now();
        $targets = collect($ownedAccountIds)->map(fn (int $accountId): array => [
            'user_id' => $user->id,
            'resource_type' => self::AccountTarget,
            'resource_id' => $accountId,
            'created_at' => $now,
            'updated_at' => $now,
        ])->push([
            'user_id' => $user->id,
            'resource_type' => self::ScopeSnapshotTarget,
            'resource_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        AccountErasureTarget::query()->upsert(
            $targets,
            ['user_id', 'resource_type', 'resource_id'],
            ['updated_at'],
        );
    }

    /**
     * @param  Collection<int, \stdClass>  $memberships
     * @param  array<int, int>  $ownedAccountIds
     */
    private function assertSoleOwnedAccounts(User $user, Collection $memberships, array $ownedAccountIds): void
    {
        foreach ($ownedAccountIds as $accountId) {
            $accountMemberships = $memberships->filter(
                fn (object $membership): bool => (int) $membership->account_id === $accountId,
            );
            $ownerMembership = $accountMemberships->first();

            if ($accountMemberships->count() !== 1
                || ! is_object($ownerMembership)
                || (int) $ownerMembership->user_id !== $user->id
                || $ownerMembership->role !== 'owner') {
                throw AccountErasureFailed::ownershipTransferRequired();
            }
        }
    }

    private function scopeWasSnapshotted(User $user): bool
    {
        return AccountErasureTarget::query()
            ->where('user_id', $user->id)
            ->where('resource_type', self::ScopeSnapshotTarget)
            ->where('resource_id', $user->id)
            ->exists();
    }

    /** @param array<int, int|string> $accountIds */
    private function confirmTargetOwnership(User $user, array $accountIds): void
    {
        foreach ($accountIds as $accountId) {
            $memberships = DB::table('account_user')
                ->where('account_id', $accountId)
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get(['user_id', 'role']);

            $membership = $memberships->count() === 1 ? $memberships->first() : null;

            if ($membership === null
                || (int) $membership->user_id !== $user->id
                || $membership->role !== 'owner'
            ) {
                throw AccountErasureFailed::ownershipTransferRequired();
            }
        }
    }

    private function assertRestrictiveReferencesRemoved(User $user): void
    {
        $hasReferences = Photo::query()->where('user_id', $user->id)->exists()
            || PhotoGenerationBatch::query()->where('user_id', $user->id)->exists()
            || AgentConversation::query()->where('user_id', $user->id)->exists()
            || AgentConversationMessage::query()->where('user_id', $user->id)->exists()
            || PhotoOperationLease::query()->where('initiating_user_id', $user->id)->exists()
            || DB::table('project_user')->where('user_id', $user->id)->exists()
            || DB::table('account_user')->where('user_id', $user->id)->exists();

        if ($hasReferences) {
            throw AccountErasureFailed::database();
        }
    }

    private function rewindForLateReferences(User $user, AccountErasureProgress $progress): bool
    {
        $passwordBroker = config('auth.defaults.passwords', 'users');
        $passwordResetTable = config("auth.passwords.{$passwordBroker}.table", 'password_reset_tokens');

        $phase = match (true) {
            $this->photosErasedWith($user)->exists(),
            $this->batchesErasedWith($user)->exists() => 'scan_batches',
            $this->conversationsErasedWith($user)->exists() => 'advisor_conversations',
            $this->validationRunsErasedWith($user)->exists() => 'validation_runs',
            UserFeedback::query()->where('user_id', $user->id)->exists() => 'feedback',
            $this->accountInvitationsErasedWith($user)->exists() => 'account_invitations',
            $this->projectInvitationsErasedWith($user)->exists() => 'invitations',
            DB::table('project_user')->where('user_id', $user->id)->exists() => 'project_memberships',
            DB::table('account_user')->where('user_id', $user->id)->where('role', '!=', 'owner')->exists() => 'account_memberships',
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->exists(),
            DB::table($passwordResetTable)->where('email', $user->email)->exists() => 'authentication',
            PhotoOperationLease::query()
                ->where(fn (Builder $query): Builder => $query
                    ->where('initiating_user_id', $user->id)
                    ->orWhereJsonContains('protected_user_ids', $user->id))
                ->exists() => 'operation_leases',
            default => null,
        };

        if ($phase === null) {
            return false;
        }

        $this->advance($progress, $phase);

        return true;
    }

    private function advanceOnly(AccountErasureProgress $progress, string $nextPhase): bool
    {
        DB::transaction(function () use ($progress, $nextPhase): void {
            $lockedProgress = $this->lockProgress($progress, $progress->phase);

            if ($lockedProgress !== null) {
                $this->advance($lockedProgress, $nextPhase);
            }
        }, attempts: 3);

        return false;
    }

    private function lockProgress(AccountErasureProgress $progress, string $expectedPhase): ?AccountErasureProgress
    {
        $lockedProgress = AccountErasureProgress::query()->lockForUpdate()->find($progress->id);

        return $lockedProgress?->phase === $expectedPhase ? $lockedProgress : null;
    }

    private function moveCursor(AccountErasureProgress $progress, int $cursor): void
    {
        $progress->increment('revision', 1, [
            'cursor' => $cursor,
        ]);
    }

    private function advance(AccountErasureProgress $progress, string $phase): void
    {
        $progress->increment('revision', 1, [
            'phase' => $phase,
            'cursor' => 0,
        ]);
    }

    private function heartbeat(AccountErasureProgress $progress): void
    {
        $progress->increment('revision');
    }

    private function chunkSize(): int
    {
        return max(1, min(500, (int) config('photostudio.account_erasure_chunk_size', 50)));
    }

    /** @throws AuthorizationException */
    private function authorize(User $user): void
    {
        $authenticatedUser = Auth::guard('web')->user();

        if (! $authenticatedUser instanceof User || ! $authenticatedUser->is($user)) {
            throw new AuthorizationException;
        }
    }
}
