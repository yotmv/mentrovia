<?php

namespace App\Models;

use Database\Factories\RoadmapItemDependencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $roadmap_plan_id @property int $roadmap_plan_item_id @property int $depends_on_roadmap_plan_item_id */
#[Fillable(['roadmap_plan_id', 'roadmap_plan_item_id', 'depends_on_roadmap_plan_item_id'])]
class RoadmapItemDependency extends Model
{
    /** @use HasFactory<RoadmapItemDependencyFactory> */
    use HasFactory;

    /** @return BelongsTo<RoadmapPlanItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(RoadmapPlanItem::class, 'roadmap_plan_item_id');
    }

    /** @return BelongsTo<RoadmapPlanItem, $this> */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(RoadmapPlanItem::class, 'depends_on_roadmap_plan_item_id');
    }
}
