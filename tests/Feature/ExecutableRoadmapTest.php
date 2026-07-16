<?php

use App\Actions\Accounts\RemoveAccountMember;
use App\Actions\Accounts\StartWorkspaceErasure;
use App\Actions\Users\EraseUserAccount;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Enums\RoadmapExecutionStatus;
use App\Enums\RoadmapStatus;
use App\Enums\YesNoUnsure;
use App\Jobs\EraseWorkspaceData;
use App\Livewire\Roadmap\Plan as RoadmapPlanComponent;
use App\Models\AiOperationAudit;
use App\Models\Business;
use App\Models\RoadmapItemDependency;
use App\Models\RoadmapItemEvidence;
use App\Models\RoadmapPlan;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\RoadmapBuilder;
use App\Services\RoadmapItem;
use App\Services\RoadmapPlanMutationService;
use App\Services\RoadmapPlanReader;
use App\Services\RoadmapPlanSynchronizer;
use App\Services\WorkspaceErasureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('the first synchronization persists the complete executable template safely', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();

    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $items = $plan->items()->orderBy('sort_order')->get();

    expect($plan->business_id)->toBe($business->id)
        ->and($plan->revision)->toBe(1)
        ->and($plan->fingerprint)->toHaveLength(64)
        ->and($items)->toHaveCount(26)
        ->and($items->pluck('template_key')->unique())->toHaveCount(26)
        ->and($items->every(fn ($item): bool => $item->assigned_user_id === $owner->id))->toBeTrue()
        ->and($items->every(fn ($item): bool => $item->due_on !== null))->toBeTrue()
        ->and(RoadmapItemDependency::query()->count())->toBe(33)
        ->and($items->firstWhere('template_key', 'name-your-business')?->action_url)->toStartWith('/')
        ->and($items->firstWhere('template_key', 'name-your-business')?->action_url)->not->toContain('://');
});

test('initial synchronization blocks computed completions with open active prerequisites', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create([
        'has_business_bank' => true,
    ]);

    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $bankAccount = $plan->items()->where('template_key', 'business-bank-account')->firstOrFail();

    expect($bankAccount->computed_profile_status)->toBe(RoadmapStatus::Complete)
        ->and($bankAccount->execution_status)->toBe(RoadmapExecutionStatus::Blocked)
        ->and($bankAccount->completed_at)->toBeNull()
        ->and($bankAccount->completed_by_user_id)->toBeNull();
});

test('synchronization is idempotent and only revisions material changes', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $synchronizer = app(RoadmapPlanSynchronizer::class);

    $first = $synchronizer->syncForMember($business, $owner);
    $fingerprint = $first->fingerprint;
    $lastSyncedAt = $first->last_synced_at->clone();
    $this->travel(1)->hour();
    $second = $synchronizer->syncForMember($business->fresh(), $owner);

    expect(RoadmapPlan::query()->count())->toBe(1)
        ->and($second->revision)->toBe(1)
        ->and($second->fingerprint)->toBe($fingerprint)
        ->and($second->last_synced_at->equalTo($lastSyncedAt))->toBeTrue()
        ->and($second->items()->count())->toBe(26)
        ->and(RoadmapItemDependency::query()->count())->toBe(33);

    $business->update(['name' => 'Materially complete name']);
    $changed = $synchronizer->syncForMember($business->fresh(), $owner);

    expect($changed->revision)->toBe(2)
        ->and($changed->fingerprint)->not->toBe($fingerprint)
        ->and($changed->items()->where('template_key', 'name-your-business')->value('execution_status'))
        ->toBe(RoadmapExecutionStatus::Complete);
});

test('project-only guests cannot synchronize another workspace roadmap', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $business = Business::factory()->for($owner)->create();

    expect(fn () => app(RoadmapPlanSynchronizer::class)->syncForMember($business, $guest))
        ->toThrow(AuthorizationException::class)
        ->and(DB::table('account_user')->where('account_id', $business->account_id)->where('user_id', $guest->id)->exists())
        ->toBeFalse()
        ->and(RoadmapPlan::query()->count())->toBe(0)
        ->and($owner->currentAccount->roleFor($owner))->toBe(AccountRole::Owner);
});

test('manual execution fields and evidence survive later profile synchronization', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $item = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();
    $mutations = app(RoadmapPlanMutationService::class);

    $mutations->updateDetails(
        $account->id,
        $item->id,
        $member->id,
        $member->id,
        '2030-01-02',
        'Owner confirmed the working name.',
    );
    $mutations->updateExecutionStatus($account->id, $item->id, $member->id, RoadmapExecutionStatus::Complete);
    $evidence = $mutations->addEvidence(
        $account->id,
        $item->id,
        $member->id,
        'Name approval note',
        'https://example.test/name-approval',
        '<script>not rendered as markup</script>',
    );

    $business->update(['name' => null]);
    app(RoadmapPlanSynchronizer::class)->syncForMember($business->fresh(), $member);
    $item->refresh();

    expect($item->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($item->status_updated_by_user_id)->toBe($member->id)
        ->and($item->completed_by_user_id)->toBe($member->id)
        ->and($item->assigned_user_id)->toBe($member->id)
        ->and($item->due_on?->format('Y-m-d'))->toBe('2030-01-02')
        ->and($item->notes)->toBe('Owner confirmed the working name.')
        ->and($item->evidence()->whereKey($evidence->id)->exists())->toBeTrue();
});

test('system completion reopens when the computed profile signal changes', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['name' => 'Ready name']);
    $synchronizer = app(RoadmapPlanSynchronizer::class);
    $plan = $synchronizer->syncForMember($business, $owner);
    $item = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();

    expect($item->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($item->status_updated_at)->toBeNull()
        ->and($item->completed_by_user_id)->toBeNull();

    $business->update(['name' => null]);
    $synchronizer->syncForMember($business->fresh(), $owner);
    $item->refresh();

    expect($item->execution_status)->toBe(RoadmapExecutionStatus::NotStarted)
        ->and($item->completed_at)->toBeNull()
        ->and($item->completed_by_user_id)->toBeNull();
});

test('profile driven prerequisite reopen deterministically reopens system completed dependents first', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->formalEntity()->for($owner)->create([
        'has_business_bank' => true,
    ]);
    $synchronizer = app(RoadmapPlanSynchronizer::class);
    $plan = $synchronizer->syncForMember($business, $owner);
    $ein = $plan->items()->where('template_key', 'get-ein')->firstOrFail();
    $bankAccount = $plan->items()->where('template_key', 'business-bank-account')->firstOrFail();

    expect($ein->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($bankAccount->execution_status)->toBe(RoadmapExecutionStatus::Complete);

    $business->update(['has_ein' => YesNoUnsure::No]);
    $synchronizer->syncForMember($business->fresh(), $owner);
    $ein->refresh();
    $bankAccount->refresh();

    expect($ein->computed_profile_status)->toBe(RoadmapStatus::ToDo)
        ->and($ein->execution_status)->toBe(RoadmapExecutionStatus::NotStarted)
        ->and($ein->completed_at)->toBeNull()
        ->and($bankAccount->computed_profile_status)->toBe(RoadmapStatus::Complete)
        ->and($bankAccount->execution_status)->toBe(RoadmapExecutionStatus::Blocked)
        ->and($bankAccount->completed_at)->toBeNull();
});

test('manual dependent completion preserves provenance and pins system prerequisites during profile reconciliation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->formalEntity()->for($owner)->create([
        'has_business_bank' => true,
    ]);
    $synchronizer = app(RoadmapPlanSynchronizer::class);
    $plan = $synchronizer->syncForMember($business, $owner);
    $ein = $plan->items()->where('template_key', 'get-ein')->firstOrFail();
    $bankAccount = $plan->items()->where('template_key', 'business-bank-account')->firstOrFail();

    app(RoadmapPlanMutationService::class)->updateExecutionStatus(
        $business->account_id,
        $bankAccount->id,
        $owner->id,
        RoadmapExecutionStatus::Complete,
    );
    $business->update(['has_ein' => YesNoUnsure::No]);
    $synchronizer->syncForMember($business->fresh(), $owner);
    $ein->refresh();
    $bankAccount->refresh();

    expect($ein->computed_profile_status)->toBe(RoadmapStatus::ToDo)
        ->and($ein->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($ein->status_updated_at)->toBeNull()
        ->and($bankAccount->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($bankAccount->status_updated_at)->not->toBeNull()
        ->and($bankAccount->status_updated_by_user_id)->toBe($owner->id)
        ->and($bankAccount->completed_by_user_id)->toBe($owner->id);
});

test('completion is blocked by active prerequisites and reopening clears completion attribution', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $formation = $plan->items()->where('template_key', 'form-entity-or-register')->firstOrFail();
    $name = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();
    $mutations = app(RoadmapPlanMutationService::class);

    expect(fn () => $mutations->updateExecutionStatus(
        $business->account_id,
        $formation->id,
        $owner->id,
        RoadmapExecutionStatus::Complete,
    ))->toThrow(ValidationException::class)
        ->and($formation->fresh()->execution_status)->not->toBe(RoadmapExecutionStatus::Complete);

    $mutations->updateExecutionStatus($business->account_id, $name->id, $owner->id, RoadmapExecutionStatus::Complete);
    $completed = $name->fresh();
    $mutations->updateExecutionStatus($business->account_id, $name->id, $owner->id, RoadmapExecutionStatus::InProgress);
    $reopened = $name->fresh();

    expect($completed->completed_at)->not->toBeNull()
        ->and($completed->completed_by_user_id)->toBe($owner->id)
        ->and($reopened->completed_at)->toBeNull()
        ->and($reopened->completed_by_user_id)->toBeNull()
        ->and($reopened->status_updated_by_user_id)->toBe($owner->id);
});

test('a prerequisite cannot reopen while an active dependent remains complete', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $items = $plan->items->keyBy('template_key');
    $mutations = app(RoadmapPlanMutationService::class);

    foreach (['name-your-business', 'decide-legal-structure', 'form-entity-or-register'] as $key) {
        $mutations->updateExecutionStatus(
            $business->account_id,
            $items->get($key)->id,
            $owner->id,
            RoadmapExecutionStatus::Complete,
        );
    }

    expect(fn () => $mutations->updateExecutionStatus(
        $business->account_id,
        $items->get('name-your-business')->id,
        $owner->id,
        RoadmapExecutionStatus::InProgress,
    ))->toThrow(ValidationException::class)
        ->and($items->get('name-your-business')->fresh()?->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($items->get('form-entity-or-register')->fresh()?->execution_status)->toBe(RoadmapExecutionStatus::Complete);
});

test('roadmap evidence and assignees are strictly scoped to the active workspace', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $otherBusiness = Business::factory()->for($otherOwner)->create();
    $synchronizer = app(RoadmapPlanSynchronizer::class);
    $plan = $synchronizer->syncForMember($business, $owner);
    $otherPlan = $synchronizer->syncForMember($otherBusiness, $otherOwner);
    $item = $plan->items()->firstOrFail();
    $otherItem = $otherPlan->items()->firstOrFail();
    $mutations = app(RoadmapPlanMutationService::class);
    $otherEvidence = RoadmapItemEvidence::factory()->create([
        'roadmap_plan_item_id' => $otherItem->id,
        'added_by_user_id' => $otherOwner->id,
    ]);

    expect(fn () => $mutations->updateDetails(
        $business->account_id,
        $item->id,
        $owner->id,
        $otherOwner->id,
        null,
        null,
    ))->toThrow(ValidationException::class)
        ->and(fn () => $mutations->removeEvidence(
            $business->account_id,
            $item->id,
            $otherEvidence->id,
            $owner->id,
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => $mutations->addEvidence(
            $business->account_id,
            $item->id,
            $owner->id,
            'Unsafe reference',
            'http://example.test/reference',
            null,
        ))->toThrow(ValidationException::class)
        ->and($item->fresh()->assigned_user_id)->toBe($owner->id)
        ->and($otherEvidence->fresh())->not->toBeNull();
});

test('fingerprints and active ctas stay portable across key and application url changes', function () {
    URL::forceRootUrl('https://first.mentrovia.test');
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $synchronizer = app(RoadmapPlanSynchronizer::class);
    $first = $synchronizer->syncForMember($business, $owner);
    $snapshot = $first->items()->where('template_key', 'name-your-business')->firstOrFail();
    $fingerprint = $first->fingerprint;

    Config::set('app.key', 'base64:a-different-application-key-that-must-not-revision-the-plan');
    Config::set('app.url', 'https://second.mentrovia.test');
    URL::forceRootUrl('https://second.mentrovia.test');
    $second = $synchronizer->syncForMember($business->fresh(), $owner);
    $currentTemplate = app(RoadmapPlanReader::class)->currentTemplate($business->fresh())
        ->get('name-your-business');

    expect($snapshot->action_url)->toStartWith('/')
        ->and($snapshot->action_url)->not->toContain('first.mentrovia.test')
        ->and($second->revision)->toBe(1)
        ->and($second->fingerprint)->toBe($fingerprint)
        ->and($currentTemplate?->href)->toContain('second.mentrovia.test/branding');

    URL::forceRootUrl(null);
});

test('active presentation resolves current template text instead of stale snapshots', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $plan->items()->where('template_key', 'name-your-business')->update([
        'title' => 'STALE SNAPSHOT MUST NOT RENDER',
        'action_label' => 'STALE ACTION LABEL',
    ]);

    $this->actingAs($owner)
        ->get(route('roadmap'))
        ->assertOk()
        ->assertSee('Settle on a business name')
        ->assertDontSee('STALE SNAPSHOT MUST NOT RENDER')
        ->assertDontSee('STALE ACTION LABEL');
});

test('existing roadmap page reads do not resynchronize or write the plan', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $lastSyncedAt = $plan->last_synced_at->clone();
    $this->travel(1)->day();
    $this->actingAs($owner);

    foreach ([
        route('dashboard'),
        route('roadmap'),
        route('business.overview'),
        route('onboarding.plan-ready'),
    ] as $url) {
        $this->get($url)->assertOk();
    }

    expect($plan->fresh()?->last_synced_at?->equalTo($lastSyncedAt))->toBeTrue()
        ->and($plan->fresh()?->revision)->toBe(1);
});

test('database constraints reject cross plan dependencies', function () {
    $firstOwner = User::factory()->create();
    $secondOwner = User::factory()->create();
    $firstBusiness = Business::factory()->for($firstOwner)->create();
    $secondBusiness = Business::factory()->for($secondOwner)->create();
    $synchronizer = app(RoadmapPlanSynchronizer::class);
    $firstPlan = $synchronizer->syncForMember($firstBusiness, $firstOwner);
    $secondPlan = $synchronizer->syncForMember($secondBusiness, $secondOwner);
    $firstItem = $firstPlan->items()->firstOrFail();
    $secondItem = $secondPlan->items()->firstOrFail();

    expect(fn () => DB::table('roadmap_item_dependencies')->insert([
        'roadmap_plan_id' => $firstPlan->id,
        'roadmap_plan_item_id' => $firstItem->id,
        'depends_on_roadmap_plan_item_id' => $secondItem->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('retired template keys become inactive without losing manual history or evidence', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $retiringItem = $plan->items()->where('template_key', 'professional-support')->firstOrFail();
    $evidence = RoadmapItemEvidence::factory()->create([
        'roadmap_plan_item_id' => $retiringItem->id,
        'added_by_user_id' => $owner->id,
    ]);
    $reducedBuilder = new class extends RoadmapBuilder
    {
        /** @return Collection<int, RoadmapItem> */
        public function build(Business $business): Collection
        {
            return parent::build($business)
                ->reject(fn ($item): bool => $item->key === 'professional-support')
                ->values();
        }
    };
    $synchronizer = new RoadmapPlanSynchronizer($reducedBuilder, app(AccountMutationGate::class));

    $updated = $synchronizer->syncForMember($business, $owner);

    expect($updated->revision)->toBe(2)
        ->and($retiringItem->fresh()?->is_active)->toBeFalse()
        ->and($evidence->fresh())->not->toBeNull();
});

test('workspace members can execute the plan while forged livewire identifiers are rejected', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $item = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();
    $otherOwner = User::factory()->create();
    $otherBusiness = Business::factory()->for($otherOwner)->create();
    $otherItem = app(RoadmapPlanSynchronizer::class)
        ->syncForMember($otherBusiness, $otherOwner)
        ->items()
        ->firstOrFail();

    $component = Livewire::actingAs($member)
        ->test(RoadmapPlanComponent::class, ['businessId' => $business->id]);
    $component
        ->assertSee('Your executable roadmap')
        ->assertSee('Planning targets are internal coordination dates');
    $component->set("itemNotes.{$item->id}", 'Member-owned execution note');
    $component->call('saveItem', $item->id);
    $component->call('setStatus', $item->id, RoadmapExecutionStatus::InProgress->value)
        ->assertHasNoErrors();

    expect($item->fresh()?->notes)->toBe('Member-owned execution note')
        ->and($item->fresh()?->execution_status)->toBe(RoadmapExecutionStatus::InProgress)
        ->and(fn () => Livewire::actingAs($member)
            ->test(RoadmapPlanComponent::class, ['businessId' => $business->id])
            ->call('setStatus', $otherItem->id, RoadmapExecutionStatus::Complete->value))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => Livewire::actingAs($member)
            ->test(RoadmapPlanComponent::class, ['businessId' => $otherBusiness->id]))
        ->toThrow(ModelNotFoundException::class);
});

test('stale collaborative detail forms cannot overwrite a newer member update', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $item = app(RoadmapPlanSynchronizer::class)
        ->syncForMember($business, $owner)
        ->items()
        ->where('template_key', 'name-your-business')
        ->firstOrFail();
    $firstSession = Livewire::actingAs($owner)
        ->test(RoadmapPlanComponent::class, ['businessId' => $business->id]);
    $secondSession = Livewire::actingAs($owner)
        ->test(RoadmapPlanComponent::class, ['businessId' => $business->id]);

    $secondSession
        ->set("itemNotes.{$item->id}", 'Saved by the second session')
        ->call('saveItem', $item->id)
        ->assertHasNoErrors();
    $firstSession
        ->set("itemNotes.{$item->id}", 'Stale first-session overwrite')
        ->call('saveItem', $item->id)
        ->assertHasErrors("itemVersions.{$item->id}");

    expect($item->fresh()?->notes)->toBe('Saved by the second session');
});

test('removed and erasing members cannot mutate roadmap state', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->for($owner)->create();
    $item = app(RoadmapPlanSynchronizer::class)
        ->syncForMember($business, $owner)
        ->items()
        ->firstOrFail();
    $originalStatus = $item->execution_status;
    DB::table('account_user')->where('account_id', $account->id)->where('user_id', $member->id)->delete();

    expect(fn () => app(RoadmapPlanMutationService::class)->updateExecutionStatus(
        $account->id,
        $item->id,
        $member->id,
        RoadmapExecutionStatus::Complete,
    ))->toThrow(AuthorizationException::class)
        ->and($item->fresh()?->execution_status)->toBe($originalStatus);

    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['account_erasure_started_at' => now()])->save();

    expect(fn () => app(RoadmapPlanMutationService::class)->updateExecutionStatus(
        $account->id,
        $item->id,
        $member->id,
        RoadmapExecutionStatus::Complete,
    ))->toThrow(AuthorizationException::class)
        ->and($item->fresh()?->execution_status)->toBe($originalStatus);
});

test('member removal clears every assignment after canonical locks and preserves roadmap attribution history', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $activeItem = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();
    $inactiveItem = $plan->items()->where('template_key', 'professional-support')->firstOrFail();
    $mutations = app(RoadmapPlanMutationService::class);

    foreach ([$activeItem, $inactiveItem] as $item) {
        $mutations->updateDetails(
            $account->id,
            $item->id,
            $member->id,
            $member->id,
            null,
            'Member-owned roadmap work.',
        );
    }

    $mutations->updateExecutionStatus(
        $account->id,
        $activeItem->id,
        $member->id,
        RoadmapExecutionStatus::Complete,
    );
    $evidence = $mutations->addEvidence(
        $account->id,
        $activeItem->id,
        $member->id,
        'Member proof',
        'https://example.test/member-proof',
        null,
    );
    $reducedBuilder = new class extends RoadmapBuilder
    {
        /** @return Collection<int, RoadmapItem> */
        public function build(Business $business): Collection
        {
            return parent::build($business)
                ->reject(fn (RoadmapItem $item): bool => $item->key === 'professional-support')
                ->values();
        }
    };
    (new RoadmapPlanSynchronizer($reducedBuilder, app(AccountMutationGate::class)))
        ->syncForMember($business, $owner);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(RemoveAccountMember::class)->handle($account, $owner, $member);
    $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...))->values();
    $selectsFrom = fn (string $query, string $table): bool => preg_match('/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i', $query) === 1;
    $businessLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'businesses') && str_contains($query, 'order by'));
    $planLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'roadmap_plans') && str_contains($query, 'order by'));
    $itemLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'roadmap_plan_items') && str_contains($query, 'order by'));
    $membershipDelete = $queries->search(fn (string $query): bool => str_starts_with($query, 'delete from') && $selectsFrom($query, 'account_user'));
    $activeItem->refresh();
    $inactiveItem->refresh();
    $evidence->refresh();

    expect([$businessLock, $planLock, $itemLock, $membershipDelete])->each->toBeInt()
        ->and($businessLock)->toBeLessThan($planLock)
        ->and($planLock)->toBeLessThan($itemLock)
        ->and($itemLock)->toBeLessThan($membershipDelete)
        ->and($account->isMember($member))->toBeFalse()
        ->and($activeItem->assigned_user_id)->toBeNull()
        ->and($activeItem->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($activeItem->completed_by_user_id)->toBe($member->id)
        ->and($activeItem->status_updated_by_user_id)->toBe($member->id)
        ->and($evidence->added_by_user_id)->toBe($member->id)
        ->and($inactiveItem->is_active)->toBeFalse()
        ->and($inactiveItem->assigned_user_id)->toBeNull();

    app(RoadmapPlanSynchronizer::class)->syncForMember($business->fresh(), $owner);
    $inactiveItem->refresh();

    expect($inactiveItem->is_active)->toBeTrue()
        ->and($inactiveItem->assigned_user_id)->toBeNull();
});

test('workspace erasure removes roadmap data while preserving users and permanent ai audits', function () {
    Queue::fake();
    config([
        'photostudio.workspace_erasure_chunk_size' => 50,
        'photostudio.workspace_erasure_chunks_per_job' => 50,
    ]);
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $business = Business::factory()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $item = $plan->items()->firstOrFail();
    $evidence = RoadmapItemEvidence::factory()->create([
        'roadmap_plan_item_id' => $item->id,
        'added_by_user_id' => $owner->id,
    ]);
    $operationId = fake()->uuid();
    $audit = AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Started,
        'occurred_at' => now(),
    ]);
    AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Succeeded,
        'occurred_at' => now(),
    ]);
    $this->actingAs($owner);
    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');

    (new EraseWorkspaceData($account->id, (string) $progress->dispatch_token))
        ->handle(app(WorkspaceErasureService::class));

    expect(RoadmapPlan::query()->whereKey($plan->id)->exists())->toBeFalse()
        ->and(RoadmapItemEvidence::query()->whereKey($evidence->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($owner->id)->exists())->toBeTrue()
        ->and($audit->fresh()?->account_id)->toBe($account->id);
});

test('roadmap synchronization proves account users membership capability business plan lock order', function () {
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->for($owner)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(RoadmapPlanSynchronizer::class)->syncForMember($business, $member);

    $queries = collect(DB::getQueryLog())->pluck('query')->map(strtolower(...))->values();
    $selectsFrom = fn (string $query, string $table): bool => preg_match('/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i', $query) === 1;
    $accountLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'accounts'));
    $userLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'users') && str_contains($query, 'account_user'));
    $membershipLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'account_user') && preg_match('/order by\s+[`"]?user_id[`"]?\s+asc/i', $query) === 1);
    $capabilityLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'account_entitlements'));
    $businessLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'businesses'));
    $planLock = $queries->search(fn (string $query): bool => $selectsFrom($query, 'roadmap_plans'));

    expect([$accountLock, $userLock, $membershipLock, $capabilityLock, $businessLock, $planLock])
        ->each->toBeInt()
        ->and($accountLock)->toBeLessThan($userLock)
        ->and($userLock)->toBeLessThan($membershipLock)
        ->and($membershipLock)->toBeLessThan($capabilityLock)
        ->and($capabilityLock)->toBeLessThan($businessLock)
        ->and($businessLock)->toBeLessThan($planLock)
        ->and($queries[$userLock])->toMatch('/order by\s+[`"]?id[`"]?\s+asc/i');
});

test('focus views use persisted execution priority and overdue ordering with responsive badges', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $name = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();
    $structure = $plan->items()->where('template_key', 'decide-legal-structure')->firstOrFail();
    $mutations = app(RoadmapPlanMutationService::class);

    foreach ([$name, $structure] as $item) {
        $mutations->updateExecutionStatus(
            $business->account_id,
            $item->id,
            $owner->id,
            RoadmapExecutionStatus::InProgress,
        );
    }

    $mutations->updateDetails($business->account_id, $name->id, $owner->id, $owner->id, today()->subDay()->format('Y-m-d'), null);
    $mutations->updateDetails($business->account_id, $structure->id, $owner->id, $owner->id, today()->addDay()->format('Y-m-d'), null);

    $this->actingAs($owner)
        ->get(route('roadmap', ['focus' => 'now']))
        ->assertOk()
        ->assertSeeInOrder(['Settle on a business name', 'Decide on a legal structure'])
        ->assertSee('Profile:')
        ->assertSee('Execution: In progress')
        ->assertSee('Overdue')
        ->assertSee('xl:grid-cols-2', escape: false)
        ->assertDontSee('Set up a tax reserve savings account');
});

test('next action surfaces exclude items with unmet active prerequisites', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $reader = app(RoadmapPlanReader::class);
    $blockedTitle = 'Form your entity or confirm your registration';

    expect($reader->nextActions($plan, 26)->pluck('template_key')->all())
        ->not->toContain('form-entity-or-register');

    $this->actingAs($owner);

    foreach ([
        route('dashboard'),
        route('business.overview'),
        route('onboarding.plan-ready'),
    ] as $url) {
        $this->get($url)->assertOk()->assertDontSee($blockedTitle);
    }

    $mutations = app(RoadmapPlanMutationService::class);

    foreach (['name-your-business', 'decide-legal-structure'] as $templateKey) {
        $mutations->updateExecutionStatus(
            $business->account_id,
            $plan->items()->where('template_key', $templateKey)->firstOrFail()->id,
            $owner->id,
            RoadmapExecutionStatus::Complete,
        );
    }

    expect($reader->nextActions($plan->fresh(), 26)->pluck('template_key')->all())
        ->toContain('form-entity-or-register');

    $this->get(route('dashboard'))->assertOk()->assertSee($blockedTitle);
});

test('roadmap focus normalizes invalid url and hydrated values with pressed state semantics', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);

    $component = Livewire::withQueryParams(['focus' => 'invalid-focus'])
        ->actingAs($owner)
        ->test(RoadmapPlanComponent::class, ['businessId' => $business->id])
        ->assertSet('focus', 'all')
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('aria-pressed="false"');

    $component->set('focus', 'forged-focus')
        ->assertSet('focus', 'all')
        ->call('setFocus', 'now')
        ->assertSet('focus', 'now')
        ->assertSeeHtml('aria-pressed="true"');
});

test('user erasure nulls attribution but preserves another workspace roadmap and manual completion', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->startingFromScratch()->for($owner)->create();
    $plan = app(RoadmapPlanSynchronizer::class)->syncForMember($business, $owner);
    $item = $plan->items()->where('template_key', 'name-your-business')->firstOrFail();
    $mutations = app(RoadmapPlanMutationService::class);
    $mutations->updateDetails($account->id, $item->id, $member->id, $member->id, '2030-02-03', 'Keep this history.');
    $mutations->updateExecutionStatus($account->id, $item->id, $member->id, RoadmapExecutionStatus::Complete);
    $evidence = $mutations->addEvidence($account->id, $item->id, $member->id, 'Member proof', 'https://example.test/proof', null);

    $this->actingAs($member);
    app(EraseUserAccount::class)->handle($member);
    simulateWorkspaceErasureAndFinishUser($member->id);
    $item->refresh();
    $evidence->refresh();

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(RoadmapPlan::query()->whereKey($plan->id)->exists())->toBeTrue()
        ->and($item->assigned_user_id)->toBeNull()
        ->and($item->completed_by_user_id)->toBeNull()
        ->and($item->status_updated_by_user_id)->toBeNull()
        ->and($item->status_updated_at)->not->toBeNull()
        ->and($item->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($evidence->added_by_user_id)->toBeNull();

    app(RoadmapPlanSynchronizer::class)->syncForMember($business->fresh(), $owner);

    expect($item->fresh()?->execution_status)->toBe(RoadmapExecutionStatus::Complete)
        ->and($item->fresh()?->notes)->toBe('Keep this history.');
});
