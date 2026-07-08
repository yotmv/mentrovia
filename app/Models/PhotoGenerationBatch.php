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

/**
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property GenerationBatchStatus $status
 * @property string|null $user_text
 * @property array<int, int> $input_photo_ids
 * @property array<string, mixed>|null $analysis
 * @property array<int, array<string, mixed>>|null $selected_models
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'project_id', 'user_id', 'status', 'user_text', 'input_photo_ids',
    'analysis', 'selected_models', 'error',
])]
class PhotoGenerationBatch extends Model
{
    /** @use HasFactory<PhotoGenerationBatchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationBatchStatus::class,
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
}
