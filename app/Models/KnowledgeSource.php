<?php

namespace App\Models;

use App\Enums\SourceType;
use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $knowledge_article_id
 * @property string $source_name
 * @property string $source_url
 * @property SourceType $source_type
 * @property Carbon|null $retrieved_at
 * @property Carbon|null $effective_date
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'knowledge_article_id', 'source_name', 'source_url', 'source_type',
    'retrieved_at', 'effective_date', 'notes',
])]
class KnowledgeSource extends Model
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'retrieved_at' => 'datetime',
            'effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<KnowledgeArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }
}
