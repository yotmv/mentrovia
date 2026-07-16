<?php

namespace App\Models;

use App\Enums\GenerationBatchStatus;
use Database\Factories\PhotoGenerationBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $account_id
 * @property int $project_id
 * @property int $user_id
 * @property GenerationBatchStatus $status
 * @property Carbon|null $analysis_enqueued_at
 * @property string $analysis_state
 * @property string|null $analysis_operation_uuid
 * @property string|null $analysis_execution_token
 * @property int $analysis_fence
 * @property Carbon|null $analysis_claim_expires_at
 * @property Carbon|null $analysis_provider_started_at
 * @property string|null $analysis_failure_code
 * @property string|null $user_text
 * @property array<int, int> $input_photo_ids
 * @property array<string, mixed>|null $analysis
 * @property array<int, array<string, mixed>>|null $selected_models
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_id', 'project_id', 'user_id', 'status', 'user_text', 'input_photo_ids',
    'analysis', 'selected_models', 'error', 'analysis_enqueued_at', 'analysis_state',
    'analysis_operation_uuid', 'analysis_execution_token', 'analysis_fence',
    'analysis_claim_expires_at', 'analysis_provider_started_at', 'analysis_failure_code',
])]
class PhotoGenerationBatch extends Model
{
    /** @use HasFactory<PhotoGenerationBatchFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'analysis_state' => 'pending',
        'analysis_fence' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            $accountId = Project::query()->whereKey($batch->project_id)->value('account_id');

            if (! is_numeric($accountId)) {
                throw new LogicException('A photo generation batch requires a project account snapshot.');
            }

            $batch->account_id = (int) $accountId;
        });

        static::updating(function (self $batch): void {
            if ($batch->isDirty('account_id')) {
                throw new LogicException('A photo generation batch account snapshot is immutable.');
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
            'status' => GenerationBatchStatus::class,
            'analysis_enqueued_at' => 'datetime',
            'analysis_fence' => 'integer',
            'analysis_claim_expires_at' => 'datetime',
            'analysis_provider_started_at' => 'datetime',
            'input_photo_ids' => 'array',
            'analysis' => 'array',
            'selected_models' => 'array',
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
     * @return HasMany<Photo, $this>
     */
    public function generatedPhotos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /** @return HasMany<PhotoGenerationSlot, $this> */
    public function generationSlots(): HasMany
    {
        return $this->hasMany(PhotoGenerationSlot::class);
    }

    /**
     * Get the uploaded photos that were the inputs for this batch.
     *
     * @return Collection<int, Photo>
     */
    public function inputPhotos(): Collection
    {
        return Photo::query()
            ->whereKey($this->input_photo_ids ?? [])
            ->where('project_id', $this->project_id)
            ->get();
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    public function generationPrompt(): ?string
    {
        $analyzedPrompt = data_get($this->analysis, 'group_prompt');

        if (filled($analyzedPrompt)) {
            return (string) $analyzedPrompt;
        }

        return filled($this->user_text) ? (string) $this->user_text : null;
    }
}
