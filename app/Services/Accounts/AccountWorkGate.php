<?php

namespace App\Services\Accounts;

use App\Models\Account;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

final class AccountWorkGate
{
    public function lockActive(int $accountId): ?Account
    {
        $account = Account::query()->lockForUpdate()->find($accountId);

        return $account?->erasure_started_at === null ? $account : null;
    }

    /** @throws GoneHttpException */
    public function lockActiveOrFail(int $accountId): Account
    {
        return $this->lockActive($accountId)
            ?? throw new GoneHttpException(__('This workspace is no longer accepting work.'));
    }

    public function acceptsWork(int $accountId): bool
    {
        return Account::query()
            ->whereKey($accountId)
            ->whereNull('erasure_started_at')
            ->exists();
    }
}
