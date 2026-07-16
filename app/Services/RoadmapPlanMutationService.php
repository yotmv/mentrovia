<?php

namespace App\Services;

use App\Enums\AccountCapability;
use App\Enums\RoadmapExecutionStatus;
use App\Models\RoadmapItemEvidence;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoadmapPlanMutationService
{
    public function __construct(private AccountMutationGate $mutationGate) {}

    public function updateExecutionStatus(
        int $accountId,
        int $itemId,
        int $actorUserId,
        RoadmapExecutionStatus $status,
    ): RoadmapPlanItem {
        return DB::transaction(function () use ($accountId, $itemId, $actorUserId, $status): RoadmapPlanItem {
            $this->mutationGate->lockMemberOrFail($accountId, $actorUserId, AccountCapability::Workspace);
            $plan = $this->lockPlan($accountId);
            $item = $this->lockItem($plan, $itemId);

            if ($status->isOpen()) {
                $dependentIds = DB::table('roadmap_item_dependencies')
                    ->where('depends_on_roadmap_plan_item_id', $item->id)
                    ->orderBy('roadmap_plan_item_id')
                    ->lockForUpdate()
                    ->pluck('roadmap_plan_item_id');
                $completedDependents = RoadmapPlanItem::query()
                    ->where('roadmap_plan_id', $plan->id)
                    ->whereIn('id', $dependentIds)
                    ->where('is_active', true)
                    ->where('execution_status', RoadmapExecutionStatus::Complete->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
                $hasCompletedDependent = false;

                foreach ($completedDependents as $completedDependent) {
                    $hasCompletedDependent = true;
                }

                if ($hasCompletedDependent) {
                    throw ValidationException::withMessages([
                        'executionStatus' => __('Reopen completed dependent items before changing this prerequisite.'),
                    ]);
                }
            }

            if ($status === RoadmapExecutionStatus::Complete) {
                $dependencyIds = DB::table('roadmap_item_dependencies')
                    ->where('roadmap_plan_item_id', $item->id)
                    ->orderBy('depends_on_roadmap_plan_item_id')
                    ->lockForUpdate()
                    ->pluck('depends_on_roadmap_plan_item_id');
                $blockingDependencies = RoadmapPlanItem::query()
                    ->where('roadmap_plan_id', $plan->id)
                    ->whereIn('id', $dependencyIds)
                    ->where('is_active', true)
                    ->whereNotIn('execution_status', [
                        RoadmapExecutionStatus::Complete->value,
                        RoadmapExecutionStatus::NotApplicable->value,
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
                $blockingDependencyExists = false;

                foreach ($blockingDependencies as $blockingDependency) {
                    $blockingDependencyExists = true;
                }

                if ($blockingDependencyExists) {
                    throw ValidationException::withMessages([
                        'executionStatus' => __('Complete the active prerequisites first.'),
                    ]);
                }
            }

            $item->execution_status = $status;
            $item->status_updated_at = now();
            $item->status_updated_by_user_id = $actorUserId;

            if ($status === RoadmapExecutionStatus::Complete) {
                $item->completed_at = now();
                $item->completed_by_user_id = $actorUserId;
            } else {
                $item->completed_at = null;
                $item->completed_by_user_id = null;
            }

            $item->save();

            return $item->fresh(['assignee', 'evidence']) ?? $item;
        }, attempts: 3);
    }

    public function updateDetails(
        int $accountId,
        int $itemId,
        int $actorUserId,
        ?int $assignedUserId,
        ?string $dueOn,
        ?string $notes,
        ?string $expectedVersion = null,
    ): RoadmapPlanItem {
        $validated = Validator::make([
            'assigned_user_id' => $assignedUserId,
            'due_on' => $dueOn,
            'notes' => $notes,
        ], [
            'assigned_user_id' => ['nullable', 'integer'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use (
            $accountId,
            $itemId,
            $actorUserId,
            $validated,
            $assignedUserId,
            $expectedVersion,
        ): RoadmapPlanItem {
            $context = $this->mutationGate->lockMemberAndUsersOrFail(
                $accountId,
                $actorUserId,
                $assignedUserId === null ? [] : [$assignedUserId],
                AccountCapability::Workspace,
            );

            if ($assignedUserId !== null && ! isset($context['roles'][$assignedUserId])) {
                throw ValidationException::withMessages([
                    'assignedUserId' => __('Choose an active workspace member.'),
                ]);
            }

            $plan = $this->lockPlan($accountId);
            $item = $this->lockItem($plan, $itemId);

            if ($expectedVersion !== null && ! hash_equals($expectedVersion, $this->detailsVersion($item))) {
                throw ValidationException::withMessages([
                    "itemVersions.{$item->id}" => __('This item changed in another session. Refresh before saving again.'),
                ]);
            }

            $item->update([
                'assigned_user_id' => $validated['assigned_user_id'],
                'due_on' => $validated['due_on'],
                'notes' => $validated['notes'],
            ]);

            return $item->fresh(['assignee', 'evidence']) ?? $item;
        }, attempts: 3);
    }

    public function detailsVersion(RoadmapPlanItem $item): string
    {
        return hash('sha256', json_encode([
            'assigned_user_id' => $item->assigned_user_id,
            'due_on' => $item->due_on?->format('Y-m-d'),
            'notes' => $item->notes,
        ], JSON_THROW_ON_ERROR));
    }

    public function addEvidence(
        int $accountId,
        int $itemId,
        int $actorUserId,
        string $label,
        ?string $referenceUrl,
        ?string $notes,
    ): RoadmapItemEvidence {
        $validated = Validator::make([
            'label' => $label,
            'reference_url' => $referenceUrl,
            'notes' => $notes,
        ], [
            'label' => ['required', 'string', 'max:255'],
            'reference_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($accountId, $itemId, $actorUserId, $validated): RoadmapItemEvidence {
            $this->mutationGate->lockMemberOrFail($accountId, $actorUserId, AccountCapability::Workspace);
            $plan = $this->lockPlan($accountId);
            $item = $this->lockItem($plan, $itemId);

            return $item->evidence()->create([
                ...$validated,
                'added_by_user_id' => $actorUserId,
            ]);
        }, attempts: 3);
    }

    public function removeEvidence(
        int $accountId,
        int $itemId,
        int $evidenceId,
        int $actorUserId,
    ): void {
        DB::transaction(function () use ($accountId, $itemId, $evidenceId, $actorUserId): void {
            $this->mutationGate->lockMemberOrFail($accountId, $actorUserId, AccountCapability::Workspace);
            $plan = $this->lockPlan($accountId);
            $item = $this->lockItem($plan, $itemId);
            $evidence = RoadmapItemEvidence::query()
                ->whereKey($evidenceId)
                ->where('roadmap_plan_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if (! $evidence instanceof RoadmapItemEvidence) {
                throw new AuthorizationException;
            }

            $evidence->delete();
        }, attempts: 3);
    }

    private function lockPlan(int $accountId): RoadmapPlan
    {
        $plan = RoadmapPlan::query()
            ->whereHas('business', fn ($query) => $query->where('account_id', $accountId))
            ->lockForUpdate()
            ->first();

        if (! $plan instanceof RoadmapPlan) {
            throw new AuthorizationException;
        }

        return $plan;
    }

    private function lockItem(RoadmapPlan $plan, int $itemId): RoadmapPlanItem
    {
        $item = RoadmapPlanItem::query()
            ->whereKey($itemId)
            ->where('roadmap_plan_id', $plan->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $item instanceof RoadmapPlanItem) {
            throw new AuthorizationException;
        }

        return $item;
    }
}
