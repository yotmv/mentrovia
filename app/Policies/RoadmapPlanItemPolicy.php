<?php

namespace App\Policies;

use App\Models\RoadmapPlanItem;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;

class RoadmapPlanItemPolicy
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
    public function view(User $user, RoadmapPlanItem $roadmapPlanItem): bool
    {
        return $roadmapPlanItem->plan()
            ->whereHas('business', fn ($query) => $query->where('account_id', $this->currentAccount->resolve($user)->id))
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RoadmapPlanItem $roadmapPlanItem): bool
    {
        return $this->view($user, $roadmapPlanItem);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RoadmapPlanItem $roadmapPlanItem): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RoadmapPlanItem $roadmapPlanItem): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RoadmapPlanItem $roadmapPlanItem): bool
    {
        return false;
    }
}
