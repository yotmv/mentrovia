<?php

namespace App\Models;

use Database\Factories\WorkspaceErasureProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property int|null $requested_by_user_id
 * @property string $phase
 * @property string $checkpoint
 * @property int $cursor
 * @property int $revision
 * @property int $attempts
 * @property string|null $dispatch_token
 * @property Carbon|null $enqueued_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $claim_expires_at
 * @property Carbon|null $last_progress_at
 * @property Carbon|null $storage_verified_at
 * @property string|null $billing_customer_id
 * @property string|null $billing_teardown_proof
 * @property Carbon|null $billing_teardown_completed_at
 * @property Carbon|null $completed_at
 * @property string|null $last_error_code
 */
#[Fillable([
    'account_id', 'requested_by_user_id', 'phase', 'checkpoint', 'cursor', 'revision', 'attempts',
    'dispatch_token', 'enqueued_at', 'claimed_at', 'claim_expires_at',
    'last_progress_at', 'storage_verified_at', 'completed_at', 'last_error_code',
    'billing_customer_id', 'billing_teardown_proof', 'billing_teardown_completed_at',
])]

class WorkspaceErasureProgress extends Model
{
    /** @use HasFactory<WorkspaceErasureProgressFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'phase' => 'drain_work',
        'checkpoint' => 'primary',
        'cursor' => 0,
        'revision' => 0,
        'attempts' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cursor' => 'integer',
            'revision' => 'integer',
            'attempts' => 'integer',
            'enqueued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'last_progress_at' => 'datetime',
            'storage_verified_at' => 'datetime',
            'billing_teardown_completed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return HasMany<WorkspaceErasureObject, $this> */
    public function objects(): HasMany
    {
        return $this->hasMany(WorkspaceErasureObject::class);
    }
}
