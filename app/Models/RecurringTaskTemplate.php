<?php

namespace App\Models;

use App\Enums\BusinessStage;
use App\Enums\LegalStructure;
use App\Enums\TaskCategory;
use App\Enums\TaskConfidence;
use App\Enums\TaskFrequency;
use Database\Factories\RecurringTaskTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $knowledge_article_id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property TaskCategory $category
 * @property TaskFrequency $frequency
 * @property array<string, mixed> $applies_to
 * @property array<string, mixed> $due_rule
 * @property TaskConfidence $confidence
 * @property bool $requires_professional_review
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'knowledge_article_id', 'slug', 'title', 'description', 'category',
    'frequency', 'applies_to', 'due_rule', 'confidence',
    'requires_professional_review', 'is_active',
])]
class RecurringTaskTemplate extends Model
{
    /** @use HasFactory<RecurringTaskTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TaskCategory::class,
            'frequency' => TaskFrequency::class,
            'applies_to' => 'array',
            'due_rule' => 'array',
            'confidence' => TaskConfidence::class,
            'requires_professional_review' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    /**
     * @return HasMany<BusinessTask, $this>
     */
    public function businessTasks(): HasMany
    {
        return $this->hasMany(BusinessTask::class);
    }

    public function appliesTo(Business $business): bool
    {
        $rules = $this->applies_to;

        if (! $this->matchesNullableEnumList($rules['stages'] ?? [], $business->stage)) {
            return false;
        }

        if (! $this->matchesEnumList($rules['legal_structures'] ?? [], $business->legal_structure)) {
            return false;
        }

        if (($rules['employees'] ?? 'any') === 'with' && $business->employee_count < 1) {
            return false;
        }

        if (($rules['contractors'] ?? 'any') === 'uses' && ! $business->uses_contractors) {
            return false;
        }

        if (($rules['sales_tax'] ?? 'any') === 'exposed' && ! $business->mayHaveTaxableSales()) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string>  $allowedValues
     */
    private function matchesNullableEnumList(array $allowedValues, ?BusinessStage $actual): bool
    {
        if ($allowedValues === []) {
            return true;
        }

        return $actual !== null && in_array($actual->value, $allowedValues, true);
    }

    /**
     * @param  array<int, string>  $allowedValues
     */
    private function matchesEnumList(array $allowedValues, LegalStructure $actual): bool
    {
        if ($allowedValues === []) {
            return true;
        }

        return in_array($actual->value, $allowedValues, true);
    }
}
