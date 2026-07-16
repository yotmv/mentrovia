<?php

namespace App\Policies;

use App\Enums\AccountCapability;
use App\Enums\AccountRole;
use App\Enums\ProjectPermission;
use App\Models\Project;
use App\Models\User;
use App\Services\Accounts\AccountEntitlementGate;
use App\Services\Accounts\CurrentAccount;

class ProjectPolicy
{
    public function __construct(
        private CurrentAccount $currentAccount,
        private AccountEntitlementGate $entitlements,
    ) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentAccount->resolve($user)->isMember($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        $account = $this->currentAccount->resolve($user);

        return $project->account_id === $account->id
            || $project->sharedUsers()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        $account = $this->currentAccount->resolve($user);

        return $account->isMember($user)
            && $this->entitlements->allows($account, AccountCapability::Project);
    }

    /**
     * Determine whether the user can update the model (gates uploads,
     * generation, and photo deletion within the project).
     */
    public function update(User $user, Project $project): bool
    {
        $account = $this->currentAccount->resolve($user);

        return $project->account_id === $account->id
            || $project->sharedUsers()->whereKey($user->id)
                ->wherePivot('permission', ProjectPermission::Write->value)
                ->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    /**
     * Determine whether the user can share the project with other users.
     */
    public function share(User $user, Project $project): bool
    {
        return $this->canManage($user, $project);
    }

    public function useAi(User $user, Project $project): bool
    {
        return $project->account_id === $this->currentAccount->resolve($user)->id;
    }

    private function canManage(User $user, Project $project): bool
    {
        $account = $this->currentAccount->resolve($user);

        return $project->account_id === $account->id
            && $account->hasRole($user, AccountRole::Owner, AccountRole::Admin);
    }
}
