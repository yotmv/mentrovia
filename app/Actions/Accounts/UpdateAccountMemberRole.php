<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateAccountMemberRole
{
    public function __construct(
        private ConfirmSensitiveAccountAction $confirmSensitiveAction,
        private AccountMutationGate $accountMutationGate,
    ) {}

    /** @throws AuthorizationException */
    public function handle(
        Account $account,
        User $actor,
        User $target,
        AccountRole $role,
        #[\SensitiveParameter] ?string $currentPassword,
    ): void {
        if ($role === AccountRole::Owner
            || $actor->id === $target->id
            || ! $account->hasRole($actor, AccountRole::Owner)) {
            throw new AuthorizationException;
        }

        $this->confirmSensitiveAction->handle($actor, $currentPassword);

        DB::transaction(function () use ($account, $actor, $target, $role): void {
            $context = $this->accountMutationGate->lockOwnerAndUsersOrFail(
                $account->id,
                $actor->id,
                [$target->id],
            );

            if (! isset($context['roles'][$target->id])) {
                throw new AuthorizationException;
            }

            $targetRole = $context['roles'][$target->id];

            if (! in_array($targetRole, [AccountRole::Admin, AccountRole::Member], true)) {
                throw new AuthorizationException;
            }

            DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $target->id)
                ->update(['role' => $role->value, 'updated_at' => now()]);
        }, attempts: 3);
    }
}
