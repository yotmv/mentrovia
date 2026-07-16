<?php

namespace App\Livewire\Roadmap;

use App\Enums\RoadmapExecutionStatus;
use App\Enums\RoadmapPhase;
use App\Enums\RoadmapPriority;
use App\Models\Business;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\RoadmapPlanMutationService;
use App\Services\RoadmapPlanReader;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class Plan extends Component
{
    private const array VALID_FOCUSES = ['all', 'now', 'next', 'later'];

    #[Locked]
    public int $businessId;

    #[Url(as: 'focus', history: true)]
    public string $focus = 'all';

    /** @var array<int, int|string|null> */
    public array $assignedUserIds = [];

    /** @var array<int, string|null> */
    public array $dueDates = [];

    /** @var array<int, string|null> */
    public array $itemNotes = [];

    /** @var array<int, string> */
    public array $itemVersions = [];

    /** @var array<int, string> */
    public array $evidenceLabels = [];

    /** @var array<int, string|null> */
    public array $evidenceUrls = [];

    /** @var array<int, string|null> */
    public array $evidenceNotes = [];

    protected CurrentAccount $currentAccount;

    protected RoadmapPlanMutationService $mutations;

    protected RoadmapPlanReader $roadmap;

    public function boot(
        CurrentAccount $currentAccount,
        RoadmapPlanMutationService $mutations,
        RoadmapPlanReader $roadmap,
    ): void {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        $currentAccount->resolve($user);
        $this->currentAccount = $currentAccount;
        $this->mutations = $mutations;
        $this->roadmap = $roadmap;
    }

    public function mount(int $businessId): void
    {
        $this->businessId = $businessId;
        $this->normalizeFocus();
        $this->business();

        foreach ($this->plan()->items()->where('is_active', true)->get() as $item) {
            $this->assignedUserIds[$item->id] = $item->assigned_user_id;
            $this->dueDates[$item->id] = $item->due_on?->format('Y-m-d');
            $this->itemNotes[$item->id] = $item->notes;
            $this->itemVersions[$item->id] = $this->mutations->detailsVersion($item);
        }
    }

    public function hydrate(): void
    {
        $this->normalizeFocus();
    }

    public function updatedFocus(): void
    {
        $this->normalizeFocus();
        unset($this->items);
    }

    public function setFocus(string $focus): void
    {
        $this->focus = $focus;
        $this->normalizeFocus();
        unset($this->items);
    }

    public function saveItem(int $itemId): void
    {
        $item = $this->scopedItem($itemId);
        $this->authorize('update', $item);
        $validated = $this->validate([
            "assignedUserIds.{$itemId}" => ['nullable', 'integer'],
            "dueDates.{$itemId}" => ['nullable', 'date_format:Y-m-d'],
            "itemNotes.{$itemId}" => ['nullable', 'string', 'max:2000'],
        ]);
        $assignedUserId = data_get($validated, "assignedUserIds.{$itemId}");

        $updatedItem = $this->mutations->updateDetails(
            $this->currentAccount->id(),
            $item->id,
            $this->actor()->id,
            $assignedUserId === null || $assignedUserId === '' ? null : (int) $assignedUserId,
            data_get($validated, "dueDates.{$itemId}"),
            data_get($validated, "itemNotes.{$itemId}"),
            $this->itemVersions[$itemId] ?? null,
        );
        $this->assignedUserIds[$itemId] = $updatedItem->assigned_user_id;
        $this->dueDates[$itemId] = $updatedItem->due_on?->format('Y-m-d');
        $this->itemNotes[$itemId] = $updatedItem->notes;
        $this->itemVersions[$itemId] = $this->mutations->detailsVersion($updatedItem);
        $this->refreshPlanState();
        Flux::toast(variant: 'success', text: __('Planning details saved.'));
    }

    public function setStatus(int $itemId, string $status): void
    {
        $item = $this->scopedItem($itemId);
        $this->authorize('update', $item);
        $executionStatus = RoadmapExecutionStatus::tryFrom($status);
        abort_unless($executionStatus instanceof RoadmapExecutionStatus, 422);
        $this->mutations->updateExecutionStatus(
            $this->currentAccount->id(),
            $item->id,
            $this->actor()->id,
            $executionStatus,
        );
        $this->refreshPlanState();
        Flux::toast(variant: 'success', text: __('Roadmap status updated.'));
    }

    public function markComplete(int $itemId): void
    {
        $this->setStatus($itemId, RoadmapExecutionStatus::Complete->value);
    }

    public function reopen(int $itemId): void
    {
        $this->setStatus($itemId, RoadmapExecutionStatus::InProgress->value);
    }

    public function addEvidence(int $itemId): void
    {
        $item = $this->scopedItem($itemId);
        $this->authorize('update', $item);
        $validated = $this->validate([
            "evidenceLabels.{$itemId}" => ['required', 'string', 'max:255'],
            "evidenceUrls.{$itemId}" => ['nullable', 'string', 'max:2048', 'url:https'],
            "evidenceNotes.{$itemId}" => ['nullable', 'string', 'max:2000'],
        ]);
        $this->mutations->addEvidence(
            $this->currentAccount->id(),
            $item->id,
            $this->actor()->id,
            (string) data_get($validated, "evidenceLabels.{$itemId}"),
            data_get($validated, "evidenceUrls.{$itemId}"),
            data_get($validated, "evidenceNotes.{$itemId}"),
        );
        unset($this->evidenceLabels[$itemId], $this->evidenceUrls[$itemId], $this->evidenceNotes[$itemId]);
        $this->refreshPlanState();
        Flux::toast(variant: 'success', text: __('Evidence reference added.'));
    }

    public function removeEvidence(int $itemId, int $evidenceId): void
    {
        $item = $this->scopedItem($itemId);
        $this->authorize('update', $item);
        $this->mutations->removeEvidence(
            $this->currentAccount->id(),
            $item->id,
            $evidenceId,
            $this->actor()->id,
        );
        $this->refreshPlanState();
        Flux::toast(variant: 'success', text: __('Evidence reference removed.'));
    }

    #[Computed]
    public function business(): Business
    {
        return Business::query()
            ->whereKey($this->businessId)
            ->where('account_id', $this->currentAccount->id())
            ->firstOrFail();
    }

    #[Computed]
    public function plan(): RoadmapPlan
    {
        $plan = RoadmapPlan::query()
            ->where('business_id', $this->business()->id)
            ->firstOrFail();
        $this->authorize('view', $plan);

        return $plan;
    }

    /** @return Collection<int, RoadmapPlanItem> */
    #[Computed]
    public function items(): Collection
    {
        $query = $this->plan()->items()
            ->where('is_active', true)
            ->with([
                'assignee',
                'evidence' => fn ($query) => $query->with('addedBy')->latest(),
                'dependencies.dependsOn',
            ])
            ->withCount('evidence');

        if ($this->focus !== 'all') {
            $priority = match ($this->focus) {
                'now' => RoadmapPriority::Required,
                'next' => RoadmapPriority::Recommended,
                'later' => RoadmapPriority::Optional,
                default => null,
            };

            if ($priority instanceof RoadmapPriority) {
                $query->where('priority', $priority->value)
                    ->whereNotIn('execution_status', [
                        RoadmapExecutionStatus::Complete->value,
                        RoadmapExecutionStatus::NotApplicable->value,
                    ]);
            }
        }

        return $query
            ->orderByRaw("CASE phase
                WHEN 'foundation' THEN 0 WHEN 'legal_setup' THEN 1 WHEN 'taxes' THEN 2
                WHEN 'banking' THEN 3 WHEN 'accounting' THEN 4 WHEN 'payroll' THEN 5
                WHEN 'owner_pay' THEN 6 WHEN 'branding' THEN 7 WHEN 'advertising' THEN 8 ELSE 9 END")
            ->orderByRaw('CASE WHEN due_on IS NOT NULL AND due_on < ? THEN 0 ELSE 1 END', [today()->format('Y-m-d')])
            ->orderBy('due_on')
            ->orderBy('sort_order')
            ->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function members(): Collection
    {
        return $this->currentAccount->account()->members()
            ->whereNull('users.account_erasure_started_at')
            ->orderBy('users.name')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.roadmap.plan', [
            'groupedItems' => $this->items()->groupBy(fn (RoadmapPlanItem $item): string => $item->phase->value),
            'phases' => RoadmapPhase::cases(),
            'templates' => $this->roadmap->currentTemplate($this->business()),
        ]);
    }

    private function scopedItem(int $itemId): RoadmapPlanItem
    {
        return $this->plan()->items()->whereKey($itemId)->where('is_active', true)->firstOrFail();
    }

    private function actor(): User
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function refreshPlanState(): void
    {
        unset($this->plan, $this->items);
    }

    private function normalizeFocus(): void
    {
        if (! in_array($this->focus, self::VALID_FOCUSES, true)) {
            $this->focus = 'all';
        }
    }
}
