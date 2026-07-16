<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreatePersonalAccount
{
    public function __construct(private ProvisionAccountEntitlement $provisionEntitlement) {}

    public function handle(User $user): Account
    {
        $account = DB::transaction(function () use ($user): Account {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentAccount = $lockedUser->currentAccount;
            $account = ($currentAccount instanceof Account && $currentAccount->isMember($lockedUser) ? $currentAccount : null)
                ?? $lockedUser->accounts()->wherePivot('role', AccountRole::Owner->value)->first()
                ?? Account::query()->create(['name' => $lockedUser->name.' workspace']);

            DB::table('account_user')->updateOrInsert(
                ['account_id' => $account->id, 'user_id' => $lockedUser->id],
                ['role' => AccountRole::Owner->value, 'created_at' => now(), 'updated_at' => now()],
            );
            $this->provisionEntitlement->handle($account);

            if ($lockedUser->current_account_id !== $account->id) {
                $lockedUser->forceFill(['current_account_id' => $account->id])->save();
            }

            return $account;
        });

        $user->setAttribute('current_account_id', $account->id);

        return $account;
    }
}
