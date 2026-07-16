<?php

namespace App\Models;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\FreshnessStatus;
use App\Enums\RiskLevel;
use Database\Factories\KnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $jurisdiction
 * @property ArticleCategory $category
 * @property string $body_markdown
 * @property string|null $source_summary
 * @property RiskLevel $risk_level
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $next_review_at
 * @property ArticleStatus $status
 * @property int $version
 * @property string|null $admin_review_notes
 * @property Carbon|null $admin_reviewed_at
 * @property Carbon|null $revalidation_requested_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title', 'slug', 'jurisdiction', 'category', 'body_markdown',
    'source_summary', 'risk_level', 'last_verified_at', 'next_review_at',
    'status', 'version', 'admin_review_notes', 'admin_reviewed_at',
    'revalidation_requested_at',
])]
class KnowledgeArticle extends Model
{
    /** @use HasFactory<KnowledgeArticleFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ArticleCategory::class,
            'risk_level' => RiskLevel::class,
            'status' => ArticleStatus::class,
            'last_verified_at' => 'datetime',
            'next_review_at' => 'datetime',
            'version' => 'integer',
            'admin_reviewed_at' => 'datetime',
            'revalidation_requested_at' => 'datetime',
        ];
    }

    /**
     * Limit knowledge to material approved for customer use.
     *
     * @param  Builder<KnowledgeArticle>  $query
     * @return Builder<KnowledgeArticle>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Published);
    }

    /**
     * @return HasMany<KnowledgeSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    /**
     * @return HasMany<ValidationRun, $this>
     */
    public function validationRuns(): HasMany
    {
        return $this->hasMany(ValidationRun::class);
    }

    /**
     * @return HasOne<ValidationRun, $this>
     */
    public function latestValidationRun(): HasOne
    {
        return $this->hasOne(ValidationRun::class)->latestOfMany();
    }

    public function freshnessStatus(): FreshnessStatus
    {
        if ($this->sources->isEmpty()) {
            return FreshnessStatus::MissingSources;
        }

        if (! $this->next_review_at) {
            return FreshnessStatus::Stale;
        }

        $daysUntilReview = now()->startOfDay()->diffInDays($this->next_review_at->startOfDay(), false);

        if ($daysUntilReview < 0) {
            return FreshnessStatus::Stale;
        }

        if ($daysUntilReview <= 14) {
            return FreshnessStatus::ReviewSoon;
        }

        return FreshnessStatus::Fresh;
    }

    public function isStale(): bool
    {
        return $this->freshnessStatus() === FreshnessStatus::Stale;
    }
}
