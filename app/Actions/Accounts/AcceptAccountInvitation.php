<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountErasureTarget;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class AcceptAccountInvitation
{
    /**
     * @throws AuthorizationException
     * @throws GoneHttpException
     */
    public function handle(
        AccountInvitation $invitation,
        User $recipient,
        #[\SensitiveParameter] string $plainTextToken,
    ): Account {
        if (! $recipient->hasVerifiedEmail()
            || $recipient->account_erasure_started_at !== null
            || ! hash_equals($invitation->email, AccountInvitation::normalizeEmail($recipient->email))) {
            throw new AuthorizationException;
        }

        if ($invitation->role === AccountRole::Owner) {
            throw new AuthorizationException;
        }

        $account = DB::transaction(function () use ($invitation, $recipient, $plainTextToken): Account {
            $account = Account::query()->lockForUpdate()->findOrFail($invitation->account_id);

            if ($account->erasure_started_at !== null || AccountErasureTarget::accountIsPendingErasure($account->id)) {
                throw new GoneHttpException(__('This workspace is no longer accepting invitations.'));
            }

            $lockedRecipient = User::query()->lockForUpdate()->findOrFail($recipient->id);
            $lockedInvitation = AccountInvitation::query()
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if (! $lockedRecipient->hasVerifiedEmail()
                || $lockedRecipient->account_erasure_started_at !== null
                || ! hash_equals($lockedInvitation->email, AccountInvitation::normalizeEmail($lockedRecipient->email))) {
                throw new AuthorizationException;
            }

            if (! $lockedInvitation->tokenMatches($plainTextToken)) {
                throw new AuthorizationException;
            }

            if (! $lockedInvitation->isPending()) {
                throw new GoneHttpException(__('This invitation is no longer available.'));
            }

            if ($lockedInvitation->account_id !== $account->id
                || $lockedInvitation->role === AccountRole::Owner) {
                throw new AuthorizationException;
            }

            $membershipExists = DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $lockedRecipient->id)
                ->exists();

            if (! $membershipExists) {
                DB::table('account_user')->insert([
                    'account_id' => $account->id,
                    'user_id' => $lockedRecipient->id,
                    'role' => $lockedInvitation->role->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $lockedRecipient->forceFill(['current_account_id' => $account->id])->save();
            $lockedInvitation->update([
                'accepted_by_user_id' => $lockedRecipient->id,
                'accepted_at' => now(),
            ]);

            return $account;
        }, attempts: 3);

        $recipient->setAttribute('current_account_id', $account->id);

        return $account;
    }
}
