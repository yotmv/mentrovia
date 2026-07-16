<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class RevokeAccountInvitation
{
    public function __construct(private AccountMutationGate $accountMutationGate) {}

    /** @throws AuthorizationException */
    public function handle(AccountInvitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            $account = $this->accountMutationGate->lockManagerOrFail($invitation->account_id, $actor->id);
            $actorRole = AccountRole::tryFrom((string) DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->value('role'));
            $lockedInvitation = AccountInvitation::query()
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if ($lockedInvitation->account_id !== $account->id
                || ! in_array($actorRole, [AccountRole::Owner, AccountRole::Admin], true)
                || ($actorRole === AccountRole::Admin && $lockedInvitation->role !== AccountRole::Member)) {
                throw new AuthorizationException;
            }

            if (! $lockedInvitation->isPending()) {
                throw new GoneHttpException(__('This invitation is no longer available.'));
            }

            $lockedInvitation->update(['revoked_at' => now()]);
        }, attempts: 3);
    }
}
