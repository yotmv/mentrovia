<?php

namespace App\Models;

use App\Enums\RoadmapExecutionStatus;
use App\Enums\RoadmapPhase;
use App\Enums\RoadmapPriority;
use App\Enums\RoadmapStatus;
use Carbon\CarbonInterface;
use Database\Factories\RoadmapPlanItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $roadmap_plan_id
 * @property string $template_key
 * @property RoadmapPhase $phase
 * @property RoadmapPriority $priority
 * @property string $title
 * @property string $why_it_matters
 * @property string|null $reviewer
 * @property string|null $action_url
 * @property string|null $action_label
 * @property int $sort_order
 * @property RoadmapStatus $computed_profile_status
 * @property RoadmapExecutionStatus $execution_status
 * @property bool $is_active
 * @property int|null $assigned_user_id
 * @property CarbonInterface|null $due_on
 * @property string|null $notes
 * @property CarbonInterface|null $completed_at
 * @property int|null $completed_by_user_id
 * @property CarbonInterface|null $status_updated_at
 * @property int|null $status_updated_by_user_id
 */
#[Fillable([
    'roadmap_plan_id', 'template_key', 'phase', 'priority', 'title', 'why_it_matters',
    'reviewer', 'action_url', 'action_label', 'sort_order', 'computed_profile_status',
    'execution_status', 'is_active', 'assigned_user_id', 'due_on', 'notes',
    'completed_at', 'completed_by_user_id', 'status_updated_at', 'status_updated_by_user_id',
])]
class RoadmapPlanItem extends Model
{
    /** @use HasFactory<RoadmapPlanItemFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phase' => RoadmapPhase::class,
            'priority' => RoadmapPriority::class,
            'computed_profile_status' => RoadmapStatus::class,
            'execution_status' => RoadmapExecutionStatus::class,
            'is_active' => 'boolean',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'status_updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<RoadmapPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(RoadmapPlan::class, 'roadmap_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function statusUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_updated_by_user_id');
    }

    /** @return HasMany<RoadmapItemDependency, $this> */
    public function dependencies(): HasMany
    {
        return $this->hasMany(RoadmapItemDependency::class, 'roadmap_plan_item_id');
    }

    /** @return HasMany<RoadmapItemEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(RoadmapItemEvidence::class, 'roadmap_plan_item_id');
    }
}
