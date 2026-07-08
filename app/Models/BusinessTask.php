<?php

namespace App\Models;

use App\Enums\TaskCategory;
use App\Enums\TaskConfidence;
use App\Enums\TaskFrequency;
use Database\Factories\BusinessTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $recurring_task_template_id
 * @property int|null $knowledge_article_id
 * @property string $title
 * @property string|null $description
 * @property TaskCategory $category
 * @property TaskFrequency $frequency
 * @property array<string, mixed> $due_rule
 * @property Carbon|null $due_on
 * @property TaskConfidence $confidence
 * @property bool $requires_professional_review
 * @property Carbon|null $completed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'business_id', 'recurring_task_template_id', 'knowledge_article_id',
    'title', 'description', 'category', 'frequency', 'due_rule', 'due_on',
    'confidence', 'requires_professional_review', 'completed_at', 'notes',
])]
class BusinessTask extends Model
{
    /** @use HasFactory<BusinessTaskFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TaskCategory::class,
            'frequency' => TaskFrequency::class,
            'due_rule' => 'array',
            'due_on' => 'date',
            'confidence' => TaskConfidence::class,
            'requires_professional_review' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<RecurringTaskTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(RecurringTaskTemplate::class, 'recurring_task_template_id');
    }

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    /**
     * @return HasMany<TaskCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(TaskCompletion::class);
    }
}
