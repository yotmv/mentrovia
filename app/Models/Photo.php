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
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
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
    'project_id', 'user_id', 'photo_generation_batch_id', 'kind', 'disk', 'path',
    'derivatives', 'width', 'height', 'size_bytes',
    'processing_status', 'processing_error', 'processed_at',
    'original_filename', 'text', 'text_source',
    'provider', 'model', 'mode', 'cost_usd', 'cost_source',
])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory;

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
     * Resolve a browser-usable URL, preferring short-lived signed URLs on
     * disks that support them since project photos are private content.
     * Requesting a variant walks a fallback chain so the UI never serves
     * a missing derivative — but may serve the original before the
     * derivatives job has finished.
     */
    public function url(?string $variant = null): string
    {
        $path = $this->path;

        if ($variant !== null) {
            $path = $this->derivativePath($variant)
                ?? $this->derivativePath('card')
                ?? $this->path;
        }

        $disk = Storage::disk($this->disk);

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(30));
        } catch (Throwable) {
            return $disk->url($path);
        }
    }

    /**
     * A URL that downloads the given variant (or the source file) instead
     * of displaying it, via a content-disposition hint on disks that
     * support signed URLs.
     */
    public function downloadUrl(?string $variant = null): string
    {
        $path = $variant === null
            ? $this->path
            : ($this->derivativePath($variant) ?? $this->path);

        $disk = Storage::disk($this->disk);

        try {
            return $disk->temporaryUrl($path, now()->addMinutes(30), [
                'ResponseContentDisposition' => 'attachment; filename="'.basename($path).'"',
            ]);
        } catch (Throwable) {
            return $disk->url($path);
        }
    }
}
