<?php

namespace App\Models;

use Database\Factories\RoadmapItemEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $roadmap_plan_item_id
 * @property string $label
 * @property string|null $reference_url
 * @property string|null $notes
 * @property int|null $added_by_user_id
 */
#[Fillable(['roadmap_plan_item_id', 'label', 'reference_url', 'notes', 'added_by_user_id'])]
class RoadmapItemEvidence extends Model
{
    /** @use HasFactory<RoadmapItemEvidenceFactory> */
    use HasFactory;

    /** @return BelongsTo<RoadmapPlanItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(RoadmapPlanItem::class, 'roadmap_plan_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }
}
