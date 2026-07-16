<?php

namespace App\Policies;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $account->erasure_started_at === null && $account->isMember($user);
    }

    public function useAi(User $user, Account $account): bool
    {
        return $account->erasure_started_at === null && $account->isMember($user);
    }

    public function manageAi(User $user, Account $account): bool
    {
        return $account->erasure_started_at === null && $account->hasRole($user, AccountRole::Owner, AccountRole::Admin);
    }

    public function manageMembers(User $user, Account $account): bool
    {
        return $account->erasure_started_at === null && $account->hasRole($user, AccountRole::Owner, AccountRole::Admin);
    }

    public function manageBilling(User $user, Account $account): bool
    {
        return $account->erasure_started_at === null && $account->hasRole($user, AccountRole::Owner);
    }

    public function transferOwnership(User $user, Account $account): bool
    {
        return $account->erasure_started_at === null && $account->hasRole($user, AccountRole::Owner);
    }
}
