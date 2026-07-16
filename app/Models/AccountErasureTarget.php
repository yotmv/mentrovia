<?php

namespace App\Models;

use Database\Factories\AccountErasureTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $resource_type
 * @property int $resource_id
 */
#[Fillable(['user_id', 'resource_type', 'resource_id'])]
class AccountErasureTarget extends Model
{
    /** @use HasFactory<AccountErasureTargetFactory> */
    use HasFactory;

    public static function accountIsPendingErasure(int $accountId): bool
    {
        if (Account::query()->whereKey($accountId)->whereNotNull('erasure_started_at')->exists()) {
            return true;
        }

        $targetTable = (new self)->getTable();
        $userTable = (new User)->getTable();

        return self::query()
            ->join($userTable, $userTable.'.id', '=', $targetTable.'.user_id')
            ->where($targetTable.'.resource_type', 'account')
            ->where($targetTable.'.resource_id', $accountId)
            ->whereNotNull($userTable.'.account_erasure_started_at')
            ->exists();
    }
}
