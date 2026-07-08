<?php

namespace App\Models;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Enums\RiskLevel;
use Database\Factories\KnowledgeArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title', 'slug', 'jurisdiction', 'category', 'body_markdown',
    'source_summary', 'risk_level', 'last_verified_at', 'next_review_at',
    'status', 'version',
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
        ];
    }

    /**
     * @return HasMany<KnowledgeSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }
}
