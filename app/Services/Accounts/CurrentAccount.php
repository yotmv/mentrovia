<?php

namespace App\Services\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

class CurrentAccount
{
    private ?Account $account = null;

    private ?int $resolvedForUserId = null;

    /** @throws AuthorizationException */
    public function resolve(User $user): Account
    {
        if ($this->account instanceof Account
            && $this->resolvedForUserId === $user->id
            && $this->account->id === $user->current_account_id
            && Account::query()->whereKey($this->account->id)->whereNull('erasure_started_at')->exists()
            && $this->account->isMember($user)) {
            return $this->account;
        }

        $account = Account::query()
            ->whereKey($user->current_account_id)
            ->whereNull('erasure_started_at')
            ->whereHas('members', fn ($query) => $query->whereKey($user->id))
            ->first();

        if (! $account instanceof Account) {
            throw new AuthorizationException('The selected workspace is unavailable.');
        }

        $this->account = $account;
        $this->resolvedForUserId = $user->id;

        return $account;
    }

    public function account(): Account
    {
        return $this->account
            ?? throw new LogicException('The current account has not been resolved.');
    }

    public function id(): int
    {
        return $this->account()->id;
    }

    public function forget(): void
    {
        $this->account = null;
        $this->resolvedForUserId = null;
    }
}
