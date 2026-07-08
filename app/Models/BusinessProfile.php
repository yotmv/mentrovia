<?php

namespace App\Models;

use Database\Factories\BusinessProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Key/value store for deeper per-module intake answers (e.g. marketplace
 * sales, workers' comp status, TWC account status) gathered after the core
 * intake. Core profile facts belong on structured `businesses` columns —
 * never migrate them into this table.
 *
 * @property int $id
 * @property int $business_id
 * @property string $question_key
 * @property string|null $answer_value
 * @property string|null $confidence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_id', 'question_key', 'answer_value', 'confidence'])]
class BusinessProfile extends Model
{
    /** @use HasFactory<BusinessProfileFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
