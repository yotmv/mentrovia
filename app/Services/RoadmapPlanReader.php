<?php

namespace App\Services;

use App\Enums\RoadmapExecutionStatus;
use App\Models\Business;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

class RoadmapPlanReader
{
    public function __construct(private RoadmapBuilder $builder) {}

    /** @return Collection<string, RoadmapItem> */
    public function currentTemplate(Business $business): Collection
    {
        return $this->builder->build($business)->keyBy('key');
    }

    /** @return Collection<int, RoadmapPlanItem> */
    public function nextActions(RoadmapPlan $plan, int $limit = 5): Collection
    {
        return $plan->items()
            ->where('is_active', true)
            ->whereNotIn('execution_status', [
                RoadmapExecutionStatus::Complete->value,
                RoadmapExecutionStatus::NotApplicable->value,
            ])
            ->whereNotExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('roadmap_item_dependencies as roadmap_dependency_edges')
                    ->join('roadmap_plan_items as roadmap_prerequisites', function (JoinClause $join): void {
                        $join->on(
                            'roadmap_prerequisites.id',
                            '=',
                            'roadmap_dependency_edges.depends_on_roadmap_plan_item_id',
                        )->on(
                            'roadmap_prerequisites.roadmap_plan_id',
                            '=',
                            'roadmap_dependency_edges.roadmap_plan_id',
                        );
                    })
                    ->whereColumn(
                        'roadmap_dependency_edges.roadmap_plan_item_id',
                        'roadmap_plan_items.id',
                    )
                    ->whereColumn(
                        'roadmap_dependency_edges.roadmap_plan_id',
                        'roadmap_plan_items.roadmap_plan_id',
                    )
                    ->where('roadmap_prerequisites.is_active', true)
                    ->whereNotIn('roadmap_prerequisites.execution_status', [
                        RoadmapExecutionStatus::Complete->value,
                        RoadmapExecutionStatus::NotApplicable->value,
                    ]);
            })
            ->with('assignee')
            ->withCount('evidence')
            ->orderByRaw('CASE WHEN due_on IS NOT NULL AND due_on < ? THEN 0 ELSE 1 END', [today()->format('Y-m-d')])
            ->orderByRaw("CASE priority WHEN 'required' THEN 0 WHEN 'recommended' THEN 1 ELSE 2 END")
            ->orderBy('due_on')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }
}
