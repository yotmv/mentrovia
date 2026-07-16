<?php

namespace App\Policies;

use App\Models\RoadmapItemEvidence;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;

class RoadmapItemEvidencePolicy
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
    public function view(User $user, RoadmapItemEvidence $roadmapItemEvidence): bool
    {
        return $roadmapItemEvidence->item()
            ->whereHas(
                'plan.business',
                fn ($query) => $query->where('account_id', $this->currentAccount->resolve($user)->id),
            )
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
    public function update(User $user, RoadmapItemEvidence $roadmapItemEvidence): bool
    {
        return $this->view($user, $roadmapItemEvidence);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RoadmapItemEvidence $roadmapItemEvidence): bool
    {
        return $this->view($user, $roadmapItemEvidence);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RoadmapItemEvidence $roadmapItemEvidence): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RoadmapItemEvidence $roadmapItemEvidence): bool
    {
        return false;
    }
}
