<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\AccountErasureTarget;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class CreateAccountInvitation
{
    private const int MaxInvitationsPerMinute = 10;

    private const int InvitationLifetimeDays = 7;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Account $account, User $inviter, string $email, AccountRole $role): AccountInvitation
    {
        if ($inviter->cannot('manageMembers', $account)) {
            throw new AuthorizationException;
        }

        $inviterRole = $account->roleFor($inviter);

        if ($role === AccountRole::Owner
            || ($inviterRole === AccountRole::Admin && $role !== AccountRole::Member)) {
            throw new AuthorizationException;
        }

        $normalizedEmail = AccountInvitation::normalizeEmail($email);

        if (User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->whereHas('accounts', fn ($query) => $query->whereKey($account->id))
            ->exists()) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('That person is already a member of this workspace.'),
            ]);
        }

        $result = RateLimiter::attempt(
            'account-invitations:create:'.$inviter->getAuthIdentifier(),
            self::MaxInvitationsPerMinute,
            fn (): array|bool => RateLimiter::attempt(
                $this->recipientRateLimitKey($account, $inviter, $normalizedEmail),
                1,
                fn (): array => $this->createOrRefresh($account, $inviter, $normalizedEmail, $role),
                60,
            ),
            60,
        );

        if (! is_array($result)) {
            throw ValidationException::withMessages([
                'inviteEmail' => __('Too many invitations were sent. Please wait a minute and try again.'),
            ]);
        }

        [$invitation, $plainTextToken] = $result;

        Notification::route('mail', $invitation->email)
            ->notify(new AccountInvitationNotification($invitation, $plainTextToken));

        return $invitation;
    }

    /** @return array{AccountInvitation, string} */
    private function createOrRefresh(
        Account $account,
        User $inviter,
        string $normalizedEmail,
        AccountRole $role,
    ): array {
        $plainTextToken = Str::random(64);

        $invitation = DB::transaction(function () use ($account, $inviter, $normalizedEmail, $role, $plainTextToken): AccountInvitation {
            $lockedAccount = Account::query()->lockForUpdate()->findOrFail($account->id);

            if ($lockedAccount->erasure_started_at !== null || AccountErasureTarget::accountIsPendingErasure($lockedAccount->id)) {
                throw new GoneHttpException(__('This workspace is no longer accepting invitations.'));
            }

            $lockedInviter = User::query()->lockForUpdate()->findOrFail($inviter->id);

            if ($lockedInviter->account_erasure_started_at !== null) {
                throw new AuthorizationException;
            }

            $lockedInviterRole = AccountRole::tryFrom((string) DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $lockedInviter->id)
                ->lockForUpdate()
                ->value('role'));

            if (! in_array($lockedInviterRole, [AccountRole::Owner, AccountRole::Admin], true)
                || ($lockedInviterRole === AccountRole::Admin && $role !== AccountRole::Member)) {
                throw new AuthorizationException;
            }

            AccountInvitation::query()->upsert(
                [[
                    'public_id' => bin2hex(random_bytes(20)),
                    'account_id' => $account->id,
                    'invited_by_user_id' => $inviter->id,
                    'accepted_by_user_id' => null,
                    'email' => $normalizedEmail,
                    'role' => $role->value,
                    'token_hash' => hash('sha256', $plainTextToken),
                    'expires_at' => now()->addDays(self::InvitationLifetimeDays),
                    'accepted_at' => null,
                    'revoked_at' => null,
                ]],
                ['account_id', 'email'],
                [
                    'invited_by_user_id',
                    'accepted_by_user_id',
                    'role',
                    'token_hash',
                    'expires_at',
                    'accepted_at',
                    'revoked_at',
                ],
            );

            return AccountInvitation::query()
                ->whereBelongsTo($account)
                ->where('email', $normalizedEmail)
                ->sole();
        }, attempts: 3);

        return [$invitation, $plainTextToken];
    }

    private function recipientRateLimitKey(Account $account, User $inviter, string $normalizedEmail): string
    {
        return implode(':', [
            'account-invitations:recipient',
            $inviter->getAuthIdentifier(),
            $account->getKey(),
            hash('sha256', $normalizedEmail),
        ]);
    }
}
