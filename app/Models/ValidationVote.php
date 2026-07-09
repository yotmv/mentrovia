<?php

namespace App\Models;

use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use Database\Factories\ValidationVoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $validation_run_id
 * @property TextGenerationRole $model_role
 * @property string $provider
 * @property string $model
 * @property ValidationDecision $vote
 * @property int|null $confidence
 * @property array<int, string>|null $flags
 * @property array<int, string>|null $concerns
 * @property array<string, mixed>|null $raw_response
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'validation_run_id', 'model_role', 'provider', 'model', 'vote',
    'confidence', 'flags', 'concerns', 'raw_response', 'metadata',
    'responded_at',
])]
class ValidationVote extends Model
{
    /** @use HasFactory<ValidationVoteFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'model_role' => TextGenerationRole::class,
            'vote' => ValidationDecision::class,
            'confidence' => 'integer',
            'flags' => 'array',
            'concerns' => 'array',
            'raw_response' => 'array',
            'metadata' => 'array',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ValidationRun, $this>
     */
    public function validationRun(): BelongsTo
    {
        return $this->belongsTo(ValidationRun::class);
    }
}
