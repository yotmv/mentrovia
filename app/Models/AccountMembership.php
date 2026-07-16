<?php

namespace App\Models;

use App\Enums\AccountRole;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/** @property AccountRole $role */
class AccountMembership extends Pivot
{
    protected $table = 'account_user';

    protected static function booted(): void
    {
        static::deleting(function (self $membership): void {
            if ($membership->role === AccountRole::Owner && Account::query()->whereKey($membership->account_id)->exists()) {
                throw new LogicException('Transfer or delete the account before removing its owner.');
            }
        });

        static::updating(function (self $membership): void {
            if ($membership->getOriginal('role') === AccountRole::Owner->value && $membership->role !== AccountRole::Owner) {
                throw new LogicException('Transfer ownership before changing the owner membership.');
            }
        });
    }

    protected function casts(): array
    {
        return ['role' => AccountRole::class];
    }
}
