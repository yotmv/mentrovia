<?php

namespace App\Models;

use Database\Factories\AiAccountSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id Legacy creator attribution only; never an authorization boundary.
 * @property int $account_id
 * @property bool $paid_ai_enabled
 * @property bool $hosted_ai_enabled
 * @property bool $byok_enabled
 * @property float|null $monthly_usd_limit
 * @property float|null $per_operation_usd_limit
 * @property int $max_concurrency
 */
#[Fillable(['user_id', 'account_id', 'paid_ai_enabled', 'hosted_ai_enabled', 'byok_enabled', 'monthly_usd_limit', 'per_operation_usd_limit', 'max_concurrency'])]
class AiAccountSetting extends Model
{
    /** @use HasFactory<AiAccountSettingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'paid_ai_enabled' => 'boolean',
            'hosted_ai_enabled' => 'boolean',
            'byok_enabled' => 'boolean',
            'monthly_usd_limit' => 'decimal:4',
            'per_operation_usd_limit' => 'decimal:4',
            'max_concurrency' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
