<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TransferAccountOwnership
{
    public function __construct(
        private ConfirmSensitiveAccountAction $confirmSensitiveAction,
        private AccountMutationGate $accountMutationGate,
    ) {}

    /** @throws AuthorizationException */
    public function handle(
        Account $account,
        User $owner,
        User $target,
        #[\SensitiveParameter] ?string $currentPassword,
    ): void {
        if ($owner->id === $target->id || ! $account->hasRole($owner, AccountRole::Owner)) {
            throw new AuthorizationException;
        }

        if ($target->account_erasure_started_at !== null) {
            throw new AuthorizationException;
        }

        $this->confirmSensitiveAction->handle($owner, $currentPassword);

        DB::transaction(function () use ($account, $owner, $target): void {
            $context = $this->accountMutationGate->lockOwnerAndUsersOrFail(
                $account->id,
                $owner->id,
                [$target->id],
            );

            if (! isset($context['roles'][$target->id])) {
                throw new AuthorizationException;
            }

            $targetRole = $context['roles'][$target->id];
            $lockedTarget = $context['users'][$target->id];

            if (! in_array($targetRole, [AccountRole::Admin, AccountRole::Member], true)
                || $lockedTarget->account_erasure_started_at !== null) {
                throw new AuthorizationException;
            }

            DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $owner->id)
                ->update(['role' => AccountRole::Admin->value, 'updated_at' => now()]);
            DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $target->id)
                ->update(['role' => AccountRole::Owner->value, 'updated_at' => now()]);
        }, attempts: 3);
    }
}
