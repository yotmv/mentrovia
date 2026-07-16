<?php

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SwitchCurrentAccount
{
    public function __construct(private CurrentAccount $currentAccount) {}

    /** @throws AuthorizationException */
    public function handle(User $user, Account $account): Account
    {
        $account = DB::transaction(function () use ($user, $account): Account {
            $lockedAccount = Account::query()->lockForUpdate()->findOrFail($account->id);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($lockedAccount->erasure_started_at !== null
                || $lockedUser->account_erasure_started_at !== null
                || ! DB::table('account_user')
                    ->where('account_id', $lockedAccount->id)
                    ->where('user_id', $lockedUser->id)
                    ->exists()) {
                throw new AuthorizationException;
            }

            $lockedUser->forceFill(['current_account_id' => $lockedAccount->id])->save();

            return $lockedAccount;
        }, attempts: 3);

        $user->setAttribute('current_account_id', $account->id);
        $this->currentAccount->forget();
        $this->currentAccount->resolve($user);

        return $account;
    }
}
