<?php

namespace App\Models;

use App\Enums\PhotoCostSource;
use App\Enums\PhotoGenerationSlotStatus;
use Database\Factories\PhotoGenerationSlotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $photo_generation_batch_id
 * @property string $provider
 * @property string $model
 * @property bool $uses_byok
 * @property string|null $actual_provider
 * @property string|null $actual_model
 * @property float|null $actual_cost_usd
 * @property PhotoCostSource|null $actual_cost_source
 * @property string $mode
 * @property string $operation_uuid
 * @property PhotoGenerationSlotStatus $status
 * @property string|null $execution_token
 * @property int $fence
 * @property Carbon|null $enqueued_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $claim_expires_at
 * @property Carbon|null $provider_started_at
 * @property string|null $staged_disk
 * @property string $staging_prefix
 * @property string|null $staged_path
 * @property int|null $photo_id
 * @property string|null $failure_code
 * @property Carbon|null $completed_at
 * @property Carbon|null $manual_review_at
 */
#[Fillable([
    'photo_generation_batch_id', 'provider', 'model', 'uses_byok', 'actual_provider',
    'actual_model', 'actual_cost_usd', 'actual_cost_source', 'mode', 'operation_uuid',
    'status', 'execution_token', 'fence', 'enqueued_at', 'claimed_at',
    'claim_expires_at', 'provider_started_at', 'staged_disk', 'staging_prefix',
    'staged_path', 'photo_id', 'failure_code', 'completed_at', 'manual_review_at',
])]
class PhotoGenerationSlot extends Model
{
    /** @use HasFactory<PhotoGenerationSlotFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'fence' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PhotoGenerationSlotStatus::class,
            'uses_byok' => 'boolean',
            'actual_cost_usd' => 'decimal:6',
            'actual_cost_source' => PhotoCostSource::class,
            'fence' => 'integer',
            'enqueued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'provider_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'manual_review_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PhotoGenerationBatch, $this> */
    public function generationBatch(): BelongsTo
    {
        return $this->belongsTo(PhotoGenerationBatch::class, 'photo_generation_batch_id');
    }

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }
}
