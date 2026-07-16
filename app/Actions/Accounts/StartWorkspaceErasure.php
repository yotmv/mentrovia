<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Jobs\EraseWorkspaceData;
use App\Models\Account;
use App\Models\AccountErasureTarget;
use App\Models\AccountInvitation;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Models\WorkspaceErasureProgress;
use App\Services\Accounts\CurrentAccount;
use App\Services\Accounts\CurrentAccountRepairer;
use App\Services\LifecycleRuntime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StartWorkspaceErasure
{
    public function __construct(
        private ConfirmSensitiveAccountAction $confirmSensitiveAction,
        private CurrentAccountRepairer $currentAccountRepairer,
        private CurrentAccount $currentAccount,
        private LifecycleRuntime $runtime,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        Account $account,
        User $actor,
        string $workspaceName,
        #[\SensitiveParameter] ?string $currentPassword,
    ): WorkspaceErasureProgress {
        if (! Auth::guard('web')->user()?->is($actor)) {
            throw new AuthorizationException;
        }

        $this->confirmSensitiveAction->handle($actor, $currentPassword);
        $this->runtime->assertReady();

        $progress = DB::transaction(
            fn (): WorkspaceErasureProgress => $this->startLocked($account->id, $actor->id, $workspaceName, false),
            attempts: 3,
        );

        $freshActor = $actor->fresh();

        if ($freshActor instanceof User) {
            $actor->setAttribute('current_account_id', $freshActor->current_account_id);
            $this->currentAccount->forget();
            $this->currentAccount->resolve($freshActor);
        }

        return $progress;
    }

    public function handleForUserErasure(int $accountId, int $ownerUserId): WorkspaceErasureProgress
    {
        $this->runtime->assertReady();

        return DB::transaction(
            fn (): WorkspaceErasureProgress => $this->startLocked($accountId, $ownerUserId, null, true),
            attempts: 3,
        );
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function startLocked(
        int $accountId,
        int $actorUserId,
        ?string $workspaceName,
        bool $fromUserErasure,
    ): WorkspaceErasureProgress {
        $account = Account::query()->lockForUpdate()->findOrFail($accountId);
        $existing = WorkspaceErasureProgress::query()->where('account_id', $account->id)->lockForUpdate()->first();

        if ($account->erasure_started_at !== null) {
            if ($existing?->requested_by_user_id !== $actorUserId) {
                throw new AuthorizationException;
            }

            return $existing;
        }

        $memberIds = DB::table('account_user')
            ->where('account_id', $account->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->all();
        $members = User::query()->whereKey($memberIds)->orderBy('id')->lockForUpdate()->get();
        $memberships = DB::table('account_user')
            ->where('account_id', $account->id)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get(['user_id', 'role']);
        $actorMembership = $memberships->firstWhere('user_id', $actorUserId);
        $actor = $members->firstWhere('id', $actorUserId);

        if (! $actor instanceof User
            || ! is_object($actorMembership)
            || $actorMembership->role !== AccountRole::Owner->value
        ) {
            throw new AuthorizationException;
        }

        if ($fromUserErasure) {
            $targetExists = AccountErasureTarget::query()
                ->where('user_id', $actor->id)
                ->where('resource_type', 'account')
                ->where('resource_id', $account->id)
                ->exists();

            if ($actor->account_erasure_started_at === null || ! $targetExists || $memberships->count() !== 1) {
                throw new AuthorizationException;
            }
        } elseif ($actor->account_erasure_started_at !== null) {
            throw new AuthorizationException;
        }

        if (! $fromUserErasure && (! is_string($workspaceName) || ! hash_equals($account->name, $workspaceName))) {
            throw ValidationException::withMessages([
                'workspaceName' => __('Enter the workspace name exactly to confirm deletion.'),
            ]);
        }

        $account->forceFill(['erasure_started_at' => now()])->save();
        $progress = $existing ?? WorkspaceErasureProgress::query()->create([
            'account_id' => $account->id,
            'requested_by_user_id' => $actor->id,
        ]);
        $projectIds = Project::query()
            ->where('account_id', $account->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        AccountInvitation::query()
            ->where('account_id', $account->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        ProjectInvitation::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
        DB::table('project_user')->whereIn('project_id', $projectIds)->delete();

        foreach ($members as $member) {
            if ($fromUserErasure && $member->id === $actor->id) {
                $member->forceFill(['current_account_id' => null])->save();
            } else {
                $this->currentAccountRepairer->repair($member, $account->id);
            }
        }

        DB::table('account_user')->where('account_id', $account->id)->delete();

        $dispatchToken = (string) Str::uuid7();
        $progress->update([
            'dispatch_token' => $dispatchToken,
            'enqueued_at' => now(),
            'claimed_at' => null,
            'claim_expires_at' => null,
            'last_error_code' => null,
        ]);
        $this->runtime->dispatchAfterCommit(
            new EraseWorkspaceData($account->id, $dispatchToken),
            $this->runtime->securityQueue(),
        );

        return $progress->refresh();
    }
}
