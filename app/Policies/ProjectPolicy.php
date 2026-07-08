<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Project $project): bool
    {
        return $project->isViewableBy($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model (gates uploads,
     * generation, and photo deletion within the project).
     */
    public function update(User $user, Project $project): bool
    {
        return $project->isEditableBy($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    /**
     * Determine whether the user can share the project with other users.
     */
    public function share(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }
}
