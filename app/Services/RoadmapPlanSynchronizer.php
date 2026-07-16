<?php

namespace App\Services;

use App\Enums\AccountCapability;
use App\Enums\RoadmapExecutionStatus;
use App\Enums\RoadmapPhase;
use App\Enums\RoadmapStatus;
use App\Models\Business;
use App\Models\RoadmapItemDependency;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use LogicException;

class RoadmapPlanSynchronizer
{
    private const array DEPENDENCY_MAP = [
        'form-entity-or-register' => ['name-your-business', 'decide-legal-structure'],
        'file-assumed-name' => ['name-your-business', 'decide-legal-structure'],
        'get-ein' => ['form-entity-or-register'],
        'business-bank-account' => ['form-entity-or-register', 'get-ein'],
        'franchise-tax-awareness' => ['form-entity-or-register'],
        'payroll-provider' => ['get-ein'],
        'bookkeeping-system' => ['business-bank-account'],
        'tax-reserve-account' => ['business-bank-account'],
        'receipt-retention' => ['bookkeeping-system'],
        'monthly-close-routine' => ['bookkeeping-system'],
        'online-presence' => ['brand-basics'],
        'first-30-days-marketing' => ['brand-basics'],
        'twc-registration' => ['payroll-provider'],
        'new-hire-basics' => ['payroll-provider'],
        'recurring-task-calendar' => [
            'form-entity-or-register',
            'file-assumed-name',
            'licenses-and-permits',
            'sales-tax-permit',
            'franchise-tax-awareness',
            'federal-tax-planning',
            'business-bank-account',
            'tax-reserve-account',
            'bookkeeping-system',
            'receipt-retention',
            'monthly-close-routine',
            'payroll-provider',
            'twc-registration',
            'new-hire-basics',
            'workers-comp-decision',
            'contractor-w9s',
        ],
    ];

    public function __construct(
        private RoadmapBuilder $builder,
        private AccountMutationGate $mutationGate,
    ) {}

    public function syncForMember(Business $business, User $actor): RoadmapPlan
    {
        return DB::transaction(function () use ($business, $actor): RoadmapPlan {
            $context = $this->mutationGate->lockMemberAndOwnerOrFail(
                $business->account_id,
                $actor->id,
                AccountCapability::Workspace,
            );
            $account = $context['account'];
            $ownerUserId = $context['owner_user_id'];

            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBusiness instanceof Business) {
                throw new AuthorizationException;
            }

            return $this->syncLocked($lockedBusiness, $ownerUserId);
        }, attempts: 3);
    }

    public function syncAfterAuthorizedProfileMutation(Business $business, int $ownerUserId): RoadmapPlan
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Roadmap synchronization requires an authorized database transaction.');
        }

        return $this->syncLocked($business, $ownerUserId);
    }

    private function syncLocked(Business $business, int $ownerUserId): RoadmapPlan
    {
        $requestLocale = App::getLocale();
        App::setLocale((string) config('app.fallback_locale', 'en'));

        try {
            $template = $this->builder->build($business);
        } finally {
            App::setLocale($requestLocale);
        }

        $this->validateDependencyMap($template);

        $snapshots = $template
            ->values()
            ->map(fn (RoadmapItem $item, int $index): array => $this->snapshot($item, $index + 1));
        $fingerprintPayload = [
            'items' => $snapshots->all(),
            'dependencies' => collect(self::DEPENDENCY_MAP)
                ->map(fn (array $dependencies): array => collect($dependencies)->sort()->values()->all())
                ->sortKeys()
                ->all(),
        ];
        $fingerprint = hash('sha256', json_encode($fingerprintPayload, JSON_THROW_ON_ERROR));
        $plan = RoadmapPlan::query()
            ->where('business_id', $business->id)
            ->lockForUpdate()
            ->first();
        $isNewPlan = ! $plan instanceof RoadmapPlan;

        if ($isNewPlan) {
            $plan = RoadmapPlan::query()->create([
                'business_id' => $business->id,
                'fingerprint' => $fingerprint,
                'revision' => 1,
                'last_synced_at' => now(),
            ]);
        }

        $existingItems = RoadmapPlanItem::query()
            ->where('roadmap_plan_id', $plan->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('template_key');
        $materiallyChanged = false;
        $activeTemplateKeys = [];
        $topologicalKeys = $this->topologicallyOrderedKeys(
            array_values($template->map(fn (RoadmapItem $item): string => $item->key)->all()),
        );

        foreach ($snapshots as $snapshot) {
            $templateKey = $snapshot['template_key'];
            $activeTemplateKeys[] = $templateKey;
            $item = $existingItems->get($templateKey);

            if (! $item instanceof RoadmapPlanItem) {
                $item = RoadmapPlanItem::query()->create([
                    ...$snapshot,
                    'roadmap_plan_id' => $plan->id,
                    'execution_status' => RoadmapExecutionStatus::NotStarted,
                    'is_active' => true,
                    'assigned_user_id' => $ownerUserId,
                    'due_on' => today()->addDays($this->planningTargetDays(RoadmapPhase::from($snapshot['phase']))),
                    'completed_at' => null,
                ]);
                $existingItems->put($templateKey, $item);
                $materiallyChanged = true;

                continue;
            }

            $item->fill([...$snapshot, 'is_active' => true]);

            if ($item->isDirty()) {
                $item->save();
                $materiallyChanged = true;
            }
        }

        $retiredItems = $existingItems->whereNotIn('template_key', $activeTemplateKeys);

        foreach ($retiredItems as $retiredItem) {
            if ($retiredItem->is_active) {
                $retiredItem->update(['is_active' => false]);
                $materiallyChanged = true;
            }
        }

        $materiallyChanged = $this->syncDependencies($plan, $existingItems) || $materiallyChanged;
        $materiallyChanged = $this->reconcileSystemStatuses($existingItems, $topologicalKeys) || $materiallyChanged;

        if (! $isNewPlan && $materiallyChanged) {
            $plan->revision++;
            $plan->fingerprint = $fingerprint;
        }

        if ($isNewPlan || $materiallyChanged) {
            $plan->last_synced_at = now();
            $plan->save();
        }

        return $plan->fresh(['items']) ?? $plan;
    }

    /**
     * @return array{
     *     template_key: string,
     *     phase: string,
     *     priority: string,
     *     title: string,
     *     why_it_matters: string,
     *     reviewer: string|null,
     *     action_url: string|null,
     *     action_label: string|null,
     *     sort_order: int,
     *     computed_profile_status: string
     * }
     */
    private function snapshot(RoadmapItem $item, int $sortOrder): array
    {
        return [
            'template_key' => $item->key,
            'phase' => $item->phase->value,
            'priority' => $item->priority->value,
            'title' => $item->title,
            'why_it_matters' => $item->whyItMatters,
            'reviewer' => $item->reviewer,
            'action_url' => $this->relativeActionUrl($item->href),
            'action_label' => $item->hrefLabel,
            'sort_order' => $sortOrder,
            'computed_profile_status' => $item->status->value,
        ];
    }

    private function relativeActionUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return null;
        }

        return $query === null ? $path : $path.'?'.$query;
    }

    private function mapStatus(RoadmapStatus $status): RoadmapExecutionStatus
    {
        return match ($status) {
            RoadmapStatus::Complete => RoadmapExecutionStatus::Complete,
            RoadmapStatus::NotApplicable => RoadmapExecutionStatus::NotApplicable,
            RoadmapStatus::NeedsInfo => RoadmapExecutionStatus::Blocked,
            RoadmapStatus::ToDo => RoadmapExecutionStatus::NotStarted,
        };
    }

    private function planningTargetDays(RoadmapPhase $phase): int
    {
        return match ($phase) {
            RoadmapPhase::Foundation => 7,
            RoadmapPhase::LegalSetup => 14,
            RoadmapPhase::Taxes, RoadmapPhase::Banking => 21,
            RoadmapPhase::Accounting, RoadmapPhase::Branding => 30,
            RoadmapPhase::Payroll, RoadmapPhase::OwnerPay, RoadmapPhase::Advertising => 35,
            RoadmapPhase::GrowthReadiness => 60,
        };
    }

    /** @param Collection<int, RoadmapItem> $template */
    private function validateDependencyMap(Collection $template): void
    {
        $keys = $template->pluck('key')->all();

        if (count($keys) !== count(array_unique($keys))) {
            throw new LogicException('Roadmap template keys must be unique.');
        }

        $keyLookup = array_fill_keys($keys, true);

        foreach (self::DEPENDENCY_MAP as $itemKey => $dependencyKeys) {
            if (count($dependencyKeys) !== count(array_unique($dependencyKeys))) {
                throw new LogicException("Roadmap dependencies for [{$itemKey}] must be unique.");
            }

            if (! isset($keyLookup[$itemKey])) {
                throw new LogicException("Roadmap dependency target [{$itemKey}] is missing from the template.");
            }

            foreach ($dependencyKeys as $dependencyKey) {
                if ($dependencyKey === $itemKey || ! isset($keyLookup[$dependencyKey])) {
                    throw new LogicException("Roadmap dependency [{$dependencyKey}] is invalid for [{$itemKey}].");
                }
            }
        }

        $visiting = [];
        $visited = [];

        foreach (array_keys(self::DEPENDENCY_MAP) as $itemKey) {
            $this->visitDependency($itemKey, $visiting, $visited);
        }
    }

    /**
     * @param  array<string, bool>  $visiting
     * @param  array<string, bool>  $visited
     */
    private function visitDependency(string $itemKey, array &$visiting, array &$visited): void
    {
        if (isset($visiting[$itemKey])) {
            throw new LogicException("Roadmap dependency cycle detected at [{$itemKey}].");
        }

        if (isset($visited[$itemKey])) {
            return;
        }

        $visiting[$itemKey] = true;

        foreach (self::DEPENDENCY_MAP[$itemKey] ?? [] as $dependencyKey) {
            $this->visitDependency($dependencyKey, $visiting, $visited);
        }

        unset($visiting[$itemKey]);
        $visited[$itemKey] = true;
    }

    /**
     * @param  list<string>  $templateKeys
     * @return list<string>
     */
    private function topologicallyOrderedKeys(array $templateKeys): array
    {
        $activeKeys = array_fill_keys($templateKeys, true);
        $visited = [];
        $orderedKeys = [];

        foreach ($templateKeys as $templateKey) {
            $this->appendTopologicalKey($templateKey, $activeKeys, $visited, $orderedKeys);
        }

        return $orderedKeys;
    }

    /**
     * @param  array<string, bool>  $activeKeys
     * @param  array<string, bool>  $visited
     * @param  list<string>  $orderedKeys
     */
    private function appendTopologicalKey(
        string $templateKey,
        array $activeKeys,
        array &$visited,
        array &$orderedKeys,
    ): void {
        if (isset($visited[$templateKey])) {
            return;
        }

        foreach (self::DEPENDENCY_MAP[$templateKey] ?? [] as $dependencyKey) {
            if (isset($activeKeys[$dependencyKey])) {
                $this->appendTopologicalKey($dependencyKey, $activeKeys, $visited, $orderedKeys);
            }
        }

        $visited[$templateKey] = true;
        $orderedKeys[] = $templateKey;
    }

    /**
     * System-managed statuses converge in two dependency-safe passes: terminal
     * statuses reopen from dependents to prerequisites, then completions flow
     * from prerequisites to dependents. Manual statuses are never rewritten.
     *
     * @param  Collection<string, RoadmapPlanItem>  $items
     * @param  list<string>  $topologicalKeys
     */
    private function reconcileSystemStatuses(Collection $items, array $topologicalKeys): bool
    {
        $pinnedSystemStatuses = [];

        foreach ($topologicalKeys as $templateKey) {
            $item = $items->get($templateKey);

            if ($item instanceof RoadmapPlanItem
                && $item->is_active
                && $item->status_updated_at !== null
                && $item->execution_status === RoadmapExecutionStatus::Complete) {
                $this->pinManualCompletionDependencies($templateKey, $items, $pinnedSystemStatuses);
            }
        }

        $finalStatuses = [];

        foreach ($topologicalKeys as $templateKey) {
            $item = $items->get($templateKey);

            if (! $item instanceof RoadmapPlanItem || ! $item->is_active) {
                continue;
            }

            if ($item->status_updated_at !== null) {
                $finalStatuses[$templateKey] = $item->execution_status;

                continue;
            }

            $status = $pinnedSystemStatuses[$templateKey]
                ?? $this->mapStatus($item->computed_profile_status);

            if ($status === RoadmapExecutionStatus::Complete) {
                foreach (self::DEPENDENCY_MAP[$templateKey] ?? [] as $dependencyKey) {
                    $dependency = $items->get($dependencyKey);

                    if ($dependency instanceof RoadmapPlanItem
                        && $dependency->is_active
                        && ! $this->isDependencySatisfied($finalStatuses[$dependencyKey] ?? $dependency->execution_status)) {
                        $status = RoadmapExecutionStatus::Blocked;

                        break;
                    }
                }
            }

            $finalStatuses[$templateKey] = $status;
        }

        $materiallyChanged = false;
        $reopenedKeys = [];

        foreach (array_reverse($topologicalKeys) as $templateKey) {
            $item = $items->get($templateKey);
            $finalStatus = $finalStatuses[$templateKey] ?? null;

            if (! $item instanceof RoadmapPlanItem
                || ! $finalStatus instanceof RoadmapExecutionStatus
                || $item->status_updated_at !== null
                || ! $this->isDependencySatisfied($item->execution_status)
                || $this->isDependencySatisfied($finalStatus)) {
                continue;
            }

            $materiallyChanged = $this->applySystemStatus($item, $finalStatus) || $materiallyChanged;
            $reopenedKeys[$templateKey] = true;
        }

        foreach ($topologicalKeys as $templateKey) {
            $item = $items->get($templateKey);
            $finalStatus = $finalStatuses[$templateKey] ?? null;

            if (! $item instanceof RoadmapPlanItem
                || ! $finalStatus instanceof RoadmapExecutionStatus
                || $item->status_updated_at !== null
                || isset($reopenedKeys[$templateKey])) {
                continue;
            }

            $materiallyChanged = $this->applySystemStatus($item, $finalStatus) || $materiallyChanged;
        }

        return $materiallyChanged;
    }

    /**
     * @param  Collection<string, RoadmapPlanItem>  $items
     * @param  array<string, RoadmapExecutionStatus>  $pinnedSystemStatuses
     */
    private function pinManualCompletionDependencies(
        string $templateKey,
        Collection $items,
        array &$pinnedSystemStatuses,
    ): void {
        foreach (self::DEPENDENCY_MAP[$templateKey] ?? [] as $dependencyKey) {
            $dependency = $items->get($dependencyKey);

            if (! $dependency instanceof RoadmapPlanItem || ! $dependency->is_active) {
                continue;
            }

            if (! $this->isDependencySatisfied($dependency->execution_status)) {
                throw new LogicException("Manual roadmap completion [{$templateKey}] has an open prerequisite [{$dependencyKey}].");
            }

            if ($dependency->status_updated_at === null) {
                $pinnedSystemStatuses[$dependencyKey] = $dependency->execution_status;
            }

            if ($dependency->execution_status === RoadmapExecutionStatus::Complete) {
                $this->pinManualCompletionDependencies($dependencyKey, $items, $pinnedSystemStatuses);
            }
        }
    }

    private function isDependencySatisfied(RoadmapExecutionStatus $status): bool
    {
        return in_array($status, [
            RoadmapExecutionStatus::Complete,
            RoadmapExecutionStatus::NotApplicable,
        ], true);
    }

    private function applySystemStatus(RoadmapPlanItem $item, RoadmapExecutionStatus $status): bool
    {
        $item->execution_status = $status;

        if ($status === RoadmapExecutionStatus::Complete) {
            $item->completed_at ??= now();
            $item->completed_by_user_id = null;
        } else {
            $item->completed_at = null;
            $item->completed_by_user_id = null;
        }

        if (! $item->isDirty()) {
            return false;
        }

        $item->save();

        return true;
    }

    /** @param Collection<string, RoadmapPlanItem> $items */
    private function syncDependencies(RoadmapPlan $plan, Collection $items): bool
    {
        $desiredPairs = collect(self::DEPENDENCY_MAP)
            ->flatMap(function (array $dependencyKeys, string $itemKey) use ($items): array {
                $item = $items->get($itemKey);

                if (! $item instanceof RoadmapPlanItem) {
                    return [];
                }

                return array_map(function (string $dependencyKey) use ($item, $items): array {
                    $dependency = $items->get($dependencyKey);

                    if (! $dependency instanceof RoadmapPlanItem
                        || $dependency->roadmap_plan_id !== $item->roadmap_plan_id) {
                        throw new LogicException('Roadmap dependencies must belong to the same plan.');
                    }

                    return [
                        'roadmap_plan_item_id' => $item->id,
                        'depends_on_roadmap_plan_item_id' => $dependency->id,
                    ];
                }, $dependencyKeys);
            })
            ->sortBy(fn (array $pair): string => $pair['roadmap_plan_item_id'].':'.$pair['depends_on_roadmap_plan_item_id'])
            ->values();
        $itemIds = $items->pluck('id');
        $existingPairs = RoadmapItemDependency::query()
            ->whereIn('roadmap_plan_item_id', $itemIds)
            ->orderBy('roadmap_plan_item_id')
            ->orderBy('depends_on_roadmap_plan_item_id')
            ->lockForUpdate()
            ->get(['roadmap_plan_item_id', 'depends_on_roadmap_plan_item_id'])
            ->map(fn (RoadmapItemDependency $dependency): array => [
                'roadmap_plan_item_id' => $dependency->roadmap_plan_item_id,
                'depends_on_roadmap_plan_item_id' => $dependency->depends_on_roadmap_plan_item_id,
            ])
            ->sortBy(fn (array $pair): string => $pair['roadmap_plan_item_id'].':'.$pair['depends_on_roadmap_plan_item_id'])
            ->values();

        if ($existingPairs->all() === $desiredPairs->all()) {
            return false;
        }

        RoadmapItemDependency::query()->whereIn('roadmap_plan_item_id', $itemIds)->delete();

        foreach ($desiredPairs as $pair) {
            RoadmapItemDependency::query()->create([
                ...$pair,
                'roadmap_plan_id' => $plan->id,
            ]);
        }

        return true;
    }
}
