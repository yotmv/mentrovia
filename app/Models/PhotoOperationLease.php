<?php

namespace App\Models;

use Database\Factories\PhotoOperationLeaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property int $account_id
 * @property int $initiating_user_id
 * @property int $project_id
 * @property array<int, int> $protected_user_ids
 * @property string $purpose
 * @property Carbon $expires_at
 * @property Carbon|null $finished_at
 */
#[Fillable(['id', 'account_id', 'initiating_user_id', 'project_id', 'protected_user_ids', 'purpose', 'expires_at', 'finished_at'])]
class PhotoOperationLease extends Model
{
    /** @use HasFactory<PhotoOperationLeaseFactory> */
    use HasFactory, MassPrunable;

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (self $lease): void {
            $accountId = Project::query()->whereKey($lease->project_id)->value('account_id');

            if (! is_numeric($accountId)) {
                throw new LogicException('A photo operation lease requires a project account snapshot.');
            }

            $lease->account_id = (int) $accountId;
        });

        static::updating(function (self $lease): void {
            if ($lease->isDirty('account_id')) {
                throw new LogicException('A photo operation lease account snapshot is immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'protected_user_ids' => 'array',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return self::query()->where(function (Builder $query): void {
            $query->where('finished_at', '<=', now()->subDay())
                ->orWhere('expires_at', '<=', now()->subDay());
        });
    }
}
