<?php

namespace App\Models;

use Database\Factories\AccountErasureProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $phase
 * @property int $cursor
 * @property int $revision
 * @property Carbon|null $enqueued_at
 */
#[Fillable(['user_id', 'phase', 'cursor', 'revision', 'enqueued_at'])]
class AccountErasureProgress extends Model
{
    /** @use HasFactory<AccountErasureProgressFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'phase' => 'scan_batches',
        'cursor' => 0,
        'revision' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cursor' => 'integer',
            'revision' => 'integer',
            'enqueued_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
