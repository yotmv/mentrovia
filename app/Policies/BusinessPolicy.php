<?php

namespace App\Policies;

use App\Enums\AccountRole;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;

class BusinessPolicy
{
    public function __construct(private CurrentAccount $currentAccount) {}

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
    public function view(User $user, Business $business): bool
    {
        return $business->account_id === $this->currentAccount->resolve($user)->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->currentAccount->resolve($user)->isMember($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Business $business): bool
    {
        return $this->view($user, $business);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Business $business): bool
    {
        $account = $this->currentAccount->resolve($user);

        return $business->account_id === $account->id
            && $account->hasRole($user, AccountRole::Owner, AccountRole::Admin);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Business $business): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Business $business): bool
    {
        return false;
    }

    public function operate(User $user, Business $business): bool
    {
        return $this->view($user, $business);
    }
}
