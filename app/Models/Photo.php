<?php

namespace App\Models;

use App\Enums\PhotoCostSource;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $account_id
 * @property int $project_id
 * @property int $user_id
 * @property int|null $photo_generation_batch_id
 * @property PhotoKind $kind
 * @property string $disk
 * @property string $path
 * @property array<string, array{path: string, width?: int|null, height?: int|null, size_bytes?: int|null}>|null $derivatives
 * @property int|null $width
 * @property int|null $height
 * @property int|null $size_bytes
 * @property PhotoProcessingStatus $processing_status
 * @property Carbon|null $derivatives_enqueued_at
 * @property Carbon|null $description_enqueued_at
 * @property string $description_state
 * @property string|null $description_operation_uuid
 * @property string|null $description_execution_token
 * @property int $description_fence
 * @property Carbon|null $description_claim_expires_at
 * @property Carbon|null $description_provider_started_at
 * @property string|null $description_failure_code
 * @property string|null $processing_error
 * @property Carbon|null $processed_at
 * @property string|null $original_filename
 * @property string|null $text
 * @property PhotoTextSource|null $text_source
 * @property string|null $provider
 * @property string|null $model
 * @property PhotoMode|null $mode
 * @property string|null $cost_usd
 * @property PhotoCostSource|null $cost_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_id', 'project_id', 'user_id', 'photo_generation_batch_id', 'kind', 'disk', 'path',
    'derivatives', 'width', 'height', 'size_bytes',
    'processing_status', 'derivatives_enqueued_at', 'description_enqueued_at',
    'description_state', 'description_operation_uuid', 'description_execution_token',
    'description_fence', 'description_claim_expires_at', 'description_provider_started_at',
    'description_failure_code', 'processing_error', 'processed_at',
    'original_filename', 'text', 'text_source',
    'provider', 'model', 'mode', 'cost_usd', 'cost_source',
])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'description_state' => 'pending',
        'description_fence' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $photo): void {
            $accountId = Project::query()->whereKey($photo->project_id)->value('account_id');

            if (! is_numeric($accountId)) {
                throw new LogicException('A photo requires a project account snapshot.');
            }

            $photo->account_id = (int) $accountId;
        });

        static::updating(function (self $photo): void {
            if ($photo->isDirty('account_id')) {
                throw new LogicException('A photo account snapshot is immutable.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PhotoKind::class,
            'derivatives' => 'array',
            'processing_status' => PhotoProcessingStatus::class,
            'derivatives_enqueued_at' => 'datetime',
            'description_enqueued_at' => 'datetime',
            'description_fence' => 'integer',
            'description_claim_expires_at' => 'datetime',
            'description_provider_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'text_source' => PhotoTextSource::class,
            'mode' => PhotoMode::class,
            'cost_usd' => 'decimal:4',
            'cost_source' => PhotoCostSource::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PhotoGenerationBatch, $this>
     */
    public function generationBatch(): BelongsTo
    {
        return $this->belongsTo(PhotoGenerationBatch::class, 'photo_generation_batch_id');
    }

    public function isUploaded(): bool
    {
        return $this->kind === PhotoKind::Uploaded;
    }

    public function isGenerated(): bool
    {
        return $this->kind === PhotoKind::Generated;
    }

    public function isProcessed(): bool
    {
        return $this->processing_status === PhotoProcessingStatus::Ready;
    }

    public function processingFailed(): bool
    {
        return $this->processing_status === PhotoProcessingStatus::Failed;
    }

    /**
     * The stored path of a derivative variant, if it has been generated.
     */
    public function derivativePath(string $variant): ?string
    {
        return $this->derivatives[$variant]['path'] ?? null;
    }

    /**
     * The stored byte size of a derivative variant, if known.
     */
    public function derivativeSizeBytes(string $variant): ?int
    {
        $bytes = $this->derivatives[$variant]['size_bytes'] ?? null;

        return $bytes !== null ? (int) $bytes : null;
    }

    /**
     * The normalized, size-constrained image that should be sent to image
     * models; falls back to the source file until derivatives exist.
     */
    public function llmInputPath(): string
    {
        return $this->derivativePath('llm-input') ?? $this->path;
    }

    /**
     * Resolve an application URL that authorizes every private photo read.
     */
    public function url(?string $variant = null): string
    {
        if ($variant !== null) {
            $variant = $this->derivativePath($variant) !== null
                ? $variant
                : ($this->derivativePath('card') !== null ? 'card' : null);
        }

        return route('projects.photos.show', [
            'project' => $this->project_id,
            'photo' => $this,
            'variant' => $variant,
        ]);
    }

    /**
     * A URL that downloads the given variant (or the source file) instead
     * of displaying it, via a content-disposition hint on disks that
     * support signed URLs.
     */
    public function downloadUrl(?string $variant = null): string
    {
        $variant = $variant !== null && $this->derivativePath($variant) !== null
            ? $variant
            : null;

        return route('projects.photos.show', [
            'project' => $this->project_id,
            'photo' => $this,
            'variant' => $variant,
            'download' => 1,
        ]);
    }
}
