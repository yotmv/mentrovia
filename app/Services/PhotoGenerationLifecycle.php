<?php

namespace App\Services;

use App\Enums\PhotoKind;
use App\Enums\ProjectPermission;
use App\Models\Account;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoOperationLease;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PhotoGenerationLifecycle
{
    public function beginAccountErasure(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedUser->account_erasure_started_at !== null) {
                $user->setAttribute('account_erasure_started_at', $lockedUser->account_erasure_started_at);

                return false;
            }

            $lockedUser->forceFill(['account_erasure_started_at' => now()])->save();
            $user->setAttribute('account_erasure_started_at', $lockedUser->account_erasure_started_at);

            return true;
        }, attempts: 3);
    }

    public function acquireForBatch(PhotoGenerationBatch $batch, string $purpose): ?PhotoOperationLease
    {
        return DB::transaction(function () use ($batch, $purpose): ?PhotoOperationLease {
            $freshBatch = PhotoGenerationBatch::query()->find($batch->id);

            if ($freshBatch === null) {
                return null;
            }

            $inputOwnerIds = $this->validatedBatchInputPhotos($freshBatch)->pluck('user_id')->all();

            return $this->createLease(
                $freshBatch->project_id,
                $freshBatch->account_id,
                $freshBatch->user_id,
                $purpose,
                $inputOwnerIds,
            );
        }, attempts: 3);
    }

    public function acquireForPhoto(Photo $photo, string $purpose, ?User $actor = null): ?PhotoOperationLease
    {
        return DB::transaction(function () use ($photo, $purpose, $actor): ?PhotoOperationLease {
            $freshPhoto = Photo::query()->find($photo->id);

            if ($freshPhoto === null) {
                return null;
            }

            return $this->createLease(
                $freshPhoto->project_id,
                $freshPhoto->account_id,
                $actor instanceof User ? $actor->id : $freshPhoto->user_id,
                $purpose,
            );
        }, attempts: 3);
    }

    /** @param array<int, int> $additionalProtectedUserIds */
    public function acquireForProject(
        Project $project,
        User $user,
        string $purpose,
        array $additionalProtectedUserIds = [],
    ): ?PhotoOperationLease {
        return DB::transaction(
            fn (): ?PhotoOperationLease => $this->createLease(
                $project->id,
                $project->account_id,
                $user->id,
                $purpose,
                $additionalProtectedUserIds,
            ),
            attempts: 3,
        );
    }

    public function finish(PhotoOperationLease $lease): void
    {
        PhotoOperationLease::query()
            ->whereKey($lease->id)
            ->whereNull('finished_at')
            ->update(['finished_at' => now()]);

        $lease->setAttribute('finished_at', now());
    }

    public function hasActiveLeases(User $user): bool
    {
        return PhotoOperationLease::query()
            ->whereNull('finished_at')
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($user): void {
                $query->where('initiating_user_id', $user->id)
                    ->orWhereJsonContains('protected_user_ids', $user->id);
            })
            ->exists();
    }

    /** @phpstan-impure */
    public function leaseIsUsable(PhotoOperationLease $lease): bool
    {
        return DB::transaction(fn (): bool => $this->lockUsableLease($lease) !== null, attempts: 3);
    }

    /**
     * @template TValue
     *
     * @param  Closure(PhotoOperationLease, Project): TValue  $operation
     * @return TValue|null
     */
    public function withUsableLease(PhotoOperationLease $lease, Closure $operation): mixed
    {
        return DB::transaction(function () use ($lease, $operation): mixed {
            $context = $this->lockUsableLease($lease);

            if ($context === null) {
                return null;
            }

            return $operation($context['lease'], $context['project']);
        }, attempts: 3);
    }

    public function batchIsActive(PhotoGenerationBatch $batch): bool
    {
        $freshBatch = PhotoGenerationBatch::query()->find($batch->id);

        if ($freshBatch === null) {
            return false;
        }

        if (! Account::query()
            ->whereKey($freshBatch->account_id)
            ->whereNull('erasure_started_at')
            ->exists()) {
            return false;
        }

        $project = Project::query()
            ->whereKey($freshBatch->project_id)
            ->where('account_id', $freshBatch->account_id)
            ->first();

        if ($project === null || ! $this->initiatorHasProjectAccess($project, $freshBatch->user_id)) {
            return false;
        }

        $inputOwnerIds = $this->validatedBatchInputPhotos($freshBatch)->pluck('user_id')->all();
        $userIds = $this->protectedUserIds($freshBatch->user_id, $inputOwnerIds);

        return User::query()
            ->whereKey($userIds)
            ->whereNull('account_erasure_started_at')
            ->count() === count($userIds);
    }

    /** @return Collection<int, Photo> */
    public function validatedBatchInputPhotos(PhotoGenerationBatch $batch): Collection
    {
        $ids = $batch->getAttribute('input_photo_ids');
        $maximum = max(1, min(100, (int) config('photostudio.max_batch_inputs', 12)));

        if (! is_array($ids)
            || $ids === []
            || count($ids) > $maximum
            || array_values($ids) !== $ids
        ) {
            throw new RuntimeException('The photo generation batch contains invalid or oversized input IDs.');
        }

        $inputIds = [];

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('The photo generation batch contains invalid or oversized input IDs.');
            }

            $inputIds[] = $id;
        }

        if (count(array_unique($inputIds, SORT_REGULAR)) !== count($inputIds)) {
            throw new RuntimeException('The photo generation batch contains invalid or oversized input IDs.');
        }

        $photos = Photo::query()
            ->whereKey($inputIds)
            ->where('project_id', $batch->project_id)
            ->where('account_id', $batch->account_id)
            ->where('kind', PhotoKind::Uploaded)
            ->get();

        if ($photos->count() !== count($inputIds)) {
            throw new RuntimeException('The photo generation batch contains missing or unowned input IDs.');
        }

        $photosById = $photos->keyBy('id');

        return new Collection(array_map(
            fn (int $id): Photo => $photosById->get($id) ?? throw new RuntimeException('The photo generation batch input could not be resolved.'),
            $inputIds,
        ));
    }

    /** @param array<int, int> $additionalProtectedUserIds */
    private function createLease(
        int $projectId,
        int $accountId,
        int $initiatingUserId,
        string $purpose,
        array $additionalProtectedUserIds = [],
    ): ?PhotoOperationLease {
        $account = Account::query()->whereKey($accountId)->lockForUpdate()->first();

        if ($account === null || $account->erasure_started_at !== null) {
            return null;
        }

        $userIds = $this->protectedUserIds($initiatingUserId, $additionalProtectedUserIds);
        $users = $this->lockUsers($userIds);

        if (! $this->allUsersAreActive($users, $userIds)
            || ! $this->lockInitiatorProjectAuthorization($accountId, $projectId, $initiatingUserId)) {
            return null;
        }

        $project = Project::query()
            ->whereKey($projectId)
            ->where('account_id', $accountId)
            ->lockForUpdate()
            ->first();

        if ($project === null) {
            return null;
        }

        return PhotoOperationLease::query()->create([
            'id' => (string) Str::uuid7(),
            'account_id' => $accountId,
            'initiating_user_id' => $initiatingUserId,
            'project_id' => $project->id,
            'protected_user_ids' => $userIds,
            'purpose' => Str::limit($purpose, 80, ''),
            'expires_at' => now()->addSeconds((int) config('photostudio.operation_lease_seconds', 600)),
        ]);
    }

    /**
     * @return array{lease: PhotoOperationLease, project: Project}|null
     */
    private function lockUsableLease(PhotoOperationLease $lease): ?array
    {
        $candidate = PhotoOperationLease::query()->find($lease->id);

        if (! $candidate instanceof PhotoOperationLease) {
            return null;
        }

        $account = Account::query()->whereKey($candidate->account_id)->lockForUpdate()->first();

        if ($account === null || $account->erasure_started_at !== null) {
            return null;
        }

        $userIds = $candidate->protected_user_ids;
        $users = $this->lockUsers($userIds);

        if (! $this->allUsersAreActive($users, $userIds)
            || ! $this->lockInitiatorProjectAuthorization(
                $candidate->account_id,
                $candidate->project_id,
                $candidate->initiating_user_id,
            )) {
            return null;
        }

        $freshLease = PhotoOperationLease::query()->lockForUpdate()->find($lease->id);

        if ($freshLease === null
            || $freshLease->account_id !== $candidate->account_id
            || $freshLease->project_id !== $candidate->project_id
            || $freshLease->initiating_user_id !== $candidate->initiating_user_id
            || $freshLease->protected_user_ids !== $userIds
            || $freshLease->finished_at !== null
            || $freshLease->expires_at->isPast()) {
            return null;
        }

        $project = Project::query()
            ->whereKey($freshLease->project_id)
            ->where('account_id', $freshLease->account_id)
            ->lockForUpdate()
            ->first();

        if ($project === null) {
            return null;
        }

        return ['lease' => $freshLease, 'project' => $project];
    }

    private function initiatorHasProjectAccess(Project $project, int $initiatingUserId): bool
    {
        $hasWorkspaceMembership = DB::table('account_user')
            ->where('account_id', $project->account_id)
            ->where('user_id', $initiatingUserId)
            ->first() !== null;

        if ($hasWorkspaceMembership) {
            return true;
        }

        return DB::table('project_user')
            ->where('project_id', $project->id)
            ->where('user_id', $initiatingUserId)
            ->where('permission', ProjectPermission::Write->value)
            ->first() !== null;
    }

    private function lockInitiatorProjectAuthorization(int $accountId, int $projectId, int $initiatingUserId): bool
    {
        $hasWorkspaceMembership = DB::table('account_user')
            ->where('account_id', $accountId)
            ->where('user_id', $initiatingUserId)
            ->lockForUpdate()
            ->first() !== null;

        if ($hasWorkspaceMembership) {
            return true;
        }

        return DB::table('project_user')
            ->where('project_id', $projectId)
            ->where('user_id', $initiatingUserId)
            ->where('permission', ProjectPermission::Write->value)
            ->lockForUpdate()
            ->first() !== null;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return Collection<int, User>
     */
    private function lockUsers(array $userIds): Collection
    {
        return User::query()->whereKey($userIds)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  array<int, int>  $userIds
     */
    private function allUsersAreActive(Collection $users, array $userIds): bool
    {
        return $users->count() === count($userIds)
            && $users->every(fn (User $user): bool => $user->account_erasure_started_at === null);
    }

    /**
     * @param  array<int, int>  $additionalUserIds
     * @return array<int, int>
     */
    private function protectedUserIds(int $initiatingUserId, array $additionalUserIds = []): array
    {
        $userIds = array_values(array_unique([$initiatingUserId, ...$additionalUserIds]));
        sort($userIds);

        return $userIds;
    }
}
