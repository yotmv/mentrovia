<?php

namespace App\Policies;

use App\Models\AccountInvitation;
use App\Models\User;

class AccountInvitationPolicy
{
    public function accept(User $user, AccountInvitation $accountInvitation): bool
    {
        return $user->account_erasure_started_at === null && hash_equals(
            $accountInvitation->email,
            AccountInvitation::normalizeEmail($user->email),
        );
    }
}
