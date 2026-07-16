<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RoadmapPlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $business_id
 * @property string $fingerprint
 * @property int $revision
 * @property CarbonInterface $last_synced_at
 */
#[Fillable(['business_id', 'fingerprint', 'revision', 'last_synced_at'])]
class RoadmapPlan extends Model
{
    /** @use HasFactory<RoadmapPlanFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return HasMany<RoadmapPlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RoadmapPlanItem::class);
    }
}
