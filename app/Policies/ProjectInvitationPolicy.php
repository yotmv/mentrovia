<?php

namespace App\Policies;

use App\Models\ProjectInvitation;
use App\Models\User;

class ProjectInvitationPolicy
{
    public function accept(User $user, ProjectInvitation $projectInvitation): bool
    {
        return $user->account_erasure_started_at === null && hash_equals(
            $projectInvitation->email,
            ProjectInvitation::normalizeEmail($user->email),
        );
    }
}
