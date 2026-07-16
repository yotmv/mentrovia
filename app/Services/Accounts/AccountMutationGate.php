<?php

namespace App\Services\Accounts;

use App\Enums\AccountCapability;
use App\Enums\AccountRole;
use App\Enums\ProjectPermission;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AccountErasureTarget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class AccountMutationGate
{
    public function __construct(private AccountEntitlementGate $entitlements) {}

    /** @throws AuthorizationException */
    public function lockMemberOrFail(
        int $accountId,
        int $actorUserId,
        ?AccountCapability $capability = null,
    ): Account {
        ['account' => $account] = $this->lockWorkspaceActor($accountId, $actorUserId);

        $this->lockCapabilityOrFail($account, $capability);

        return $account;
    }

    /**
     * Revalidates a durable actor attribution without requiring that the
     * workspace is still selected in the user's interactive session.
     *
     * @throws AuthorizationException
     * @throws GoneHttpException
     */
    public function lockActiveMemberOrFail(
        int $accountId,
        int $actorUserId,
        ?AccountCapability $capability = null,
    ): Account {
        ['account' => $account] = $this->lockActorMembership($accountId, $actorUserId);

        $this->lockCapabilityOrFail($account, $capability);

        return $account;
    }

    /** @throws AuthorizationException */
    public function lockManagerOrFail(
        int $accountId,
        int $actorUserId,
        ?AccountCapability $capability = null,
    ): Account {
        ['account' => $account, 'role' => $role] = $this->lockWorkspaceActor($accountId, $actorUserId);

        if (! in_array($role, [AccountRole::Owner, AccountRole::Admin], true)) {
            throw new AuthorizationException;
        }

        $this->lockCapabilityOrFail($account, $capability);

        return $account;
    }

    /** @throws AuthorizationException */
    public function lockOwnerOrFail(int $accountId, int $actorUserId): Account
    {
        ['account' => $account, 'role' => $role] = $this->lockWorkspaceActor($accountId, $actorUserId);

        if ($role !== AccountRole::Owner) {
            throw new AuthorizationException;
        }

        return $account;
    }

    /**
     * @param  array<int, int>  $affectedUserIds
     * @return array{account: Account, users: array<int, User>, roles: array<int, AccountRole>}
     *
     * @throws AuthorizationException
     */
    public function lockMemberAndUsersOrFail(
        int $accountId,
        int $actorUserId,
        array $affectedUserIds,
        ?AccountCapability $capability = null,
    ): array {
        $context = $this->lockWorkspaceUsers($accountId, $actorUserId, $affectedUserIds);
        $this->lockCapabilityOrFail($context['account'], $capability);

        return $context;
    }

    /**
     * Locks the current actor and the workspace owner once in ascending user
     * order before their memberships and the requested capability.
     *
     * @return array{account: Account, users: array<int, User>, roles: array<int, AccountRole>, owner_user_id: int}
     *
     * @throws AuthorizationException
     */
    public function lockMemberAndOwnerOrFail(
        int $accountId,
        int $actorUserId,
        ?AccountCapability $capability = null,
    ): array {
        $account = $this->lockAccountOrFail($accountId);
        $lockedUsers = User::query()
            ->whereIn('id', function ($query) use ($account, $actorUserId): void {
                $query->select('user_id')
                    ->from('account_user')
                    ->where('account_id', $account->id)
                    ->where(function ($membershipQuery) use ($actorUserId): void {
                        $membershipQuery
                            ->where('user_id', $actorUserId)
                            ->orWhere('role', AccountRole::Owner->value);
                    });
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $users = [];

        foreach ($lockedUsers as $lockedUser) {
            if ($lockedUser->account_erasure_started_at !== null) {
                throw new AuthorizationException;
            }

            $users[$lockedUser->id] = $lockedUser;
        }

        if (! isset($users[$actorUserId]) || $users[$actorUserId]->current_account_id !== $account->id) {
            throw new AuthorizationException;
        }

        $memberships = DB::table('account_user')
            ->where('account_id', $account->id)
            ->whereIn('user_id', array_keys($users))
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get();
        $roles = [];
        $ownerUserId = null;

        foreach ($memberships as $membership) {
            $role = AccountRole::tryFrom((string) $membership->role);

            if (! $role instanceof AccountRole) {
                continue;
            }

            $userId = (int) $membership->user_id;
            $roles[$userId] = $role;

            if ($role === AccountRole::Owner) {
                $ownerUserId = $userId;
            }
        }

        if (! is_int($ownerUserId) || ! isset($roles[$actorUserId], $users[$ownerUserId])) {
            throw new AuthorizationException;
        }

        $this->lockCapabilityOrFail($account, $capability);

        return [
            'account' => $account,
            'users' => $users,
            'roles' => $roles,
            'owner_user_id' => $ownerUserId,
        ];
    }

    /**
     * @param  array<int, int>  $affectedUserIds
     * @return array{account: Account, users: array<int, User>, roles: array<int, AccountRole>}
     *
     * @throws AuthorizationException
     */
    public function lockManagerAndUsersOrFail(int $accountId, int $actorUserId, array $affectedUserIds): array
    {
        $context = $this->lockWorkspaceUsers($accountId, $actorUserId, $affectedUserIds);

        if (! in_array($context['roles'][$actorUserId], [AccountRole::Owner, AccountRole::Admin], true)) {
            throw new AuthorizationException;
        }

        return $context;
    }

    /**
     * @param  array<int, int>  $affectedUserIds
     * @return array{account: Account, users: array<int, User>, roles: array<int, AccountRole>}
     *
     * @throws AuthorizationException
     */
    public function lockOwnerAndUsersOrFail(int $accountId, int $actorUserId, array $affectedUserIds): array
    {
        $context = $this->lockWorkspaceUsers($accountId, $actorUserId, $affectedUserIds);

        if ($context['roles'][$actorUserId] !== AccountRole::Owner) {
            throw new AuthorizationException;
        }

        return $context;
    }

    /**
     * Workspace members may write projects selected through their current
     * workspace. Project-only guests must hold a locked write grant for this
     * exact project and never inherit a workspace capability.
     *
     * @throws AuthorizationException
     */
    public function lockProjectWriterOrFail(
        int $accountId,
        int $projectId,
        int $actorUserId,
        ?AccountCapability $memberCapability = null,
    ): Project {
        $account = $this->lockAccountOrFail($accountId);
        $actor = $this->lockActorOrFail($actorUserId);
        $role = $this->lockMembershipRole($account->id, $actor->id);

        if ($role instanceof AccountRole) {
            if ($actor->current_account_id !== $account->id) {
                throw new AuthorizationException;
            }

            $this->lockCapabilityOrFail($account, $memberCapability);
        } else {
            $guestPermission = DB::table('project_user')
                ->where('project_id', $projectId)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->value('permission');

            if ($guestPermission !== ProjectPermission::Write->value) {
                throw new AuthorizationException;
            }
        }

        return $this->lockProjectOrFail($account, $projectId);
    }

    /** @throws AuthorizationException */
    public function lockProjectMemberOrFail(
        int $accountId,
        int $projectId,
        int $actorUserId,
        ?AccountCapability $capability = null,
    ): Project {
        $account = $this->lockMemberOrFail($accountId, $actorUserId, $capability);

        return $this->lockProjectOrFail($account, $projectId);
    }

    /** @throws AuthorizationException */
    public function lockProjectManagerOrFail(int $accountId, int $projectId, int $actorUserId): Project
    {
        $account = $this->lockManagerOrFail($accountId, $actorUserId);

        return $this->lockProjectOrFail($account, $projectId);
    }

    /**
     * @return array{account: Account, actor: User, role: AccountRole}
     *
     * @throws AuthorizationException
     */
    private function lockWorkspaceActor(int $accountId, int $actorUserId): array
    {
        ['account' => $account, 'actor' => $actor, 'role' => $role] = $this->lockActorMembership($accountId, $actorUserId);

        if ($actor->current_account_id !== $account->id) {
            throw new AuthorizationException;
        }

        return ['account' => $account, 'actor' => $actor, 'role' => $role];
    }

    /**
     * @return array{account: Account, actor: User, role: AccountRole}
     *
     * @throws AuthorizationException
     * @throws GoneHttpException
     */
    private function lockActorMembership(int $accountId, int $actorUserId): array
    {
        $account = $this->lockAccountOrFail($accountId);
        $actor = $this->lockActorOrFail($actorUserId);
        $role = $this->lockMembershipRole($account->id, $actor->id);

        if (! $role instanceof AccountRole) {
            throw new AuthorizationException;
        }

        return ['account' => $account, 'actor' => $actor, 'role' => $role];
    }

    /**
     * Locks every involved user exactly once in a deterministic order before
     * locking their membership rows.
     *
     * @param  array<int, int>  $affectedUserIds
     * @return array{account: Account, users: array<int, User>, roles: array<int, AccountRole>}
     *
     * @throws AuthorizationException
     * @throws GoneHttpException
     */
    private function lockWorkspaceUsers(int $accountId, int $actorUserId, array $affectedUserIds): array
    {
        $account = $this->lockAccountOrFail($accountId);
        $userIds = array_values(array_unique([$actorUserId, ...$affectedUserIds]));
        sort($userIds, SORT_NUMERIC);

        $lockedUsers = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $users = [];

        foreach ($lockedUsers as $lockedUser) {
            if ($lockedUser->account_erasure_started_at !== null) {
                throw new AuthorizationException;
            }

            $users[$lockedUser->id] = $lockedUser;
        }

        if (count($users) !== count($userIds)) {
            throw new AuthorizationException;
        }

        $actor = $users[$actorUserId];

        if ($actor->current_account_id !== $account->id) {
            throw new AuthorizationException;
        }

        $memberships = DB::table('account_user')
            ->where('account_id', $account->id)
            ->whereIn('user_id', $userIds)
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get();
        $roles = [];

        foreach ($memberships as $membership) {
            $role = AccountRole::tryFrom((string) $membership->role);

            if ($role instanceof AccountRole) {
                $roles[(int) $membership->user_id] = $role;
            }
        }

        if (! isset($roles[$actorUserId])) {
            throw new AuthorizationException;
        }

        return ['account' => $account, 'users' => $users, 'roles' => $roles];
    }

    /** @throws GoneHttpException */
    private function lockAccountOrFail(int $accountId): Account
    {
        $this->ensureTransactionIsOpen();

        $account = Account::query()->lockForUpdate()->find($accountId);

        if (! $account instanceof Account
            || $account->erasure_started_at !== null
            || AccountErasureTarget::accountIsPendingErasure($accountId)) {
            throw new GoneHttpException(__('This workspace is no longer accepting work.'));
        }

        return $account;
    }

    /** @throws AuthorizationException */
    private function lockProjectOrFail(Account $account, int $projectId): Project
    {
        $project = Project::query()->lockForUpdate()->find($projectId);

        if (! $project instanceof Project || $project->account_id !== $account->id) {
            throw new AuthorizationException;
        }

        return $project;
    }

    /** @throws AuthorizationException */
    private function lockActorOrFail(int $actorUserId): User
    {
        $actor = User::query()->lockForUpdate()->find($actorUserId);

        if (! $actor instanceof User || $actor->account_erasure_started_at !== null) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function lockMembershipRole(int $accountId, int $actorUserId): ?AccountRole
    {
        $role = DB::table('account_user')
            ->where('account_id', $accountId)
            ->where('user_id', $actorUserId)
            ->lockForUpdate()
            ->value('role');

        return is_string($role) ? AccountRole::tryFrom($role) : null;
    }

    /** @throws AuthorizationException */
    private function lockCapabilityOrFail(Account $account, ?AccountCapability $capability): void
    {
        if (! $capability instanceof AccountCapability) {
            return;
        }

        $entitlement = AccountEntitlement::query()
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();
        $account->setRelation('entitlement', $entitlement);

        if (! $this->entitlements->allows($account, $capability)) {
            throw new AuthorizationException;
        }
    }

    private function ensureTransactionIsOpen(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Account mutations must be authorized inside a database transaction.');
        }
    }
}
