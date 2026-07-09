<?php

namespace App\Models;

use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use Database\Factories\ValidationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $knowledge_article_id
 * @property int|null $user_id
 * @property int|null $business_id
 * @property array<string, mixed> $normalized_request
 * @property array<string, mixed>|null $context_snapshot
 * @property ValidationRunStatus $status
 * @property ValidationDecision|null $aggregate_decision
 * @property TextGenerationRole|null $final_model_role
 * @property string|null $final_provider
 * @property string|null $final_model
 * @property int|null $confidence
 * @property array<int, string>|null $flags
 * @property array<int, string>|null $concerns
 * @property array<string, mixed>|null $raw_response
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'knowledge_article_id', 'user_id', 'business_id', 'normalized_request',
    'context_snapshot', 'status', 'aggregate_decision', 'final_model_role',
    'final_provider', 'final_model', 'confidence', 'flags', 'concerns',
    'raw_response', 'metadata', 'started_at', 'completed_at',
])]
class ValidationRun extends Model
{
    /** @use HasFactory<ValidationRunFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'normalized_request' => 'array',
            'context_snapshot' => 'array',
            'status' => ValidationRunStatus::class,
            'aggregate_decision' => ValidationDecision::class,
            'final_model_role' => TextGenerationRole::class,
            'confidence' => 'integer',
            'flags' => 'array',
            'concerns' => 'array',
            'raw_response' => 'array',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return HasMany<ValidationVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(ValidationVote::class);
    }
}
