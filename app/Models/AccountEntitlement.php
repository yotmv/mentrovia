<?php

namespace App\Models;

use App\Enums\AccountEntitlementStatus;
use Database\Factories\AccountEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property string $plan
 * @property AccountEntitlementStatus $status
 * @property Carbon|null $trial_ends_at
 */
#[Fillable(['account_id', 'plan', 'status', 'trial_ends_at'])]
class AccountEntitlement extends Model
{
    /** @use HasFactory<AccountEntitlementFactory> */
    use HasFactory;

    protected $attributes = ['plan' => 'beta', 'status' => 'active'];

    protected function casts(): array
    {
        return ['status' => AccountEntitlementStatus::class, 'trial_ends_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
