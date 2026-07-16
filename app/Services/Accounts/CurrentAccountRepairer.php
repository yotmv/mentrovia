<?php

namespace App\Services\Accounts;

use App\Actions\Accounts\ProvisionAccountEntitlement;
use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CurrentAccountRepairer
{
    public function __construct(private ProvisionAccountEntitlement $provisionEntitlement) {}

    /**
     * Repair a user that has already been locked inside an Account -> User
     * ordered transaction.
     */
    public function repair(User $lockedUser, int $removedAccountId): ?Account
    {
        if ($lockedUser->current_account_id !== $removedAccountId) {
            return $lockedUser->currentAccount;
        }

        $replacementAccountId = DB::table('account_user')
            ->join('accounts', 'accounts.id', '=', 'account_user.account_id')
            ->where('account_user.user_id', $lockedUser->id)
            ->where('account_user.account_id', '!=', $removedAccountId)
            ->whereNull('accounts.erasure_started_at')
            ->orderByRaw("CASE WHEN account_user.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('account_user.account_id')
            ->lockForUpdate()
            ->value('account_user.account_id');

        if (is_numeric($replacementAccountId)) {
            $replacement = Account::query()->findOrFail((int) $replacementAccountId);
        } else {
            $replacement = Account::query()->create(['name' => $lockedUser->name.' workspace']);
            DB::table('account_user')->insert([
                'account_id' => $replacement->id,
                'user_id' => $lockedUser->id,
                'role' => AccountRole::Owner->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->provisionEntitlement->handle($replacement);
        }

        $lockedUser->forceFill(['current_account_id' => $replacement->id])->save();

        return $replacement;
    }
}
