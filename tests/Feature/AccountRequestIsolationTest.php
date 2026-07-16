<?php

use App\Enums\AccountRole;
use App\Enums\ProjectPermission;
use App\Livewire\Advertising\Index as AdvertisingIndex;
use App\Livewire\Advisor\Ask;
use App\Livewire\Advisor\History;
use App\Livewire\Branding\Index as BrandingIndex;
use App\Livewire\Business\Intake;
use App\Livewire\Business\ProfileEditor;
use App\Livewire\Projects\Index as ProjectIndex;
use App\Models\AdvertisingKit;
use App\Models\AgentConversation;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('a forged current account selection fails closed', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $user->forceFill(['current_account_id' => $otherUser->current_account_id])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});

test('a removed membership blocks the next Livewire action', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();

    $component = Livewire::actingAs($member)->test(Intake::class);

    $account->members()->detach($member);
    app()->forgetScopedInstances();

    $component->call('next')->assertForbidden();
});

test('a removed member cannot commit through any synchronous workspace writer', function (string $writer) {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $business = Business::factory()->for($owner)->create(['has_business_bank' => false]);
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $this->actingAs($member);

    $assertBlocked = match ($writer) {
        'business' => function () use ($member, $account, $business): void {
            $component = Livewire::actingAs($member)
                ->test(ProfileEditor::class, ['section' => 'location_structure'])
                ->set('values.city', 'Blocked City');
            $account->members()->detach($member);
            app()->forgetScopedInstances();
            $component->call('save')->assertForbidden();
            expect($business->refresh()->city)->not->toBe('Blocked City');
        },
        'task' => function () use ($member, $account, $business): void {
            $task = BusinessTask::factory()->for($business)->create(['completed_at' => null]);
            $account->members()->detach($member);
            app()->forgetScopedInstances();
            $this->patch(route('tasks.update', $task), ['completed' => true])->assertForbidden();
            expect($task->refresh()->completed_at)->toBeNull();
        },
        'project' => function () use ($member, $account): void {
            $component = Livewire::actingAs($member)->test(ProjectIndex::class)
                ->set('name', 'Blocked project')
                ->set('projectDate', now()->toDateString());
            $before = Project::query()->where('account_id', $account->id)->count();
            $account->members()->detach($member);
            app()->forgetScopedInstances();
            $component->call('createProject')->assertForbidden();
            expect(Project::query()->where('account_id', $account->id)->count())->toBe($before);
        },
        'banking' => function () use ($member, $account, $business): void {
            $account->members()->detach($member);
            app()->forgetScopedInstances();
            $this->patch(route('banking-setup.items.update', ['key' => 'dedicated-checking']), [
                'completed' => true,
            ])->assertForbidden();
            expect($business->refresh()->has_business_bank)->toBeFalse();
        },
        'brand' => function () use ($member, $account, $business): void {
            $kit = BrandKit::factory()->forBusiness($business)->create([
                'name_ideas' => ['Original', 'Blocked preference'],
                'preferences' => null,
            ]);
            $component = Livewire::actingAs($member)->test(BrandingIndex::class)
                ->set('selectedKitId', $kit->id);
            $account->members()->detach($member);
            app()->forgetScopedInstances();
            $component->call('selectPreference', 'name', 1)->assertForbidden();
            expect($kit->refresh()->preferences)->toBeNull();
        },
        'advisor' => function () use ($member, $account): void {
            $conversation = AgentConversation::query()->create([
                'account_id' => $account->id,
                'user_id' => $member->id,
                'title' => 'Advisor Q&A',
            ]);
            $message = $conversation->messages()->create([
                'user_id' => $member->id,
                'agent' => 'advisor',
                'role' => 'assistant',
                'content' => 'Do not flag me after removal.',
                'attachments' => [],
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
                'meta' => [],
            ]);
            $component = Livewire::actingAs($member)->test(Ask::class);
            $account->members()->detach($member);
            app()->forgetScopedInstances();
            $component->call('reportAnswer', $message->id)->assertForbidden();
            expect($message->refresh()->meta)->toBe([]);
        },
    };

    $assertBlocked();
})->with(['business', 'task', 'project', 'banking', 'brand', 'advisor']);

test('workspace members share business tasks while cross account task ids are rejected', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['name' => 'Shared Operations']);
    $task = BusinessTask::factory()->for($business)->create([
        'title' => 'File the shared report',
        'due_on' => today(),
    ]);

    $owner->currentAccount->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $owner->current_account_id])->save();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Shared Operations');

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertSee('File the shared report');

    $this->patch(route('tasks.update', $task), ['completed' => true, 'notes' => 'Done together'])
        ->assertRedirect();

    expect($task->refresh()->completed_at)->not->toBeNull();

    $this->actingAs($outsider)
        ->patch(route('tasks.update', $task), ['completed' => true])
        ->assertForbidden();
});

test('workspace members use the selected account across business modules', function (string $routeName) {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Business::factory()->for($owner)->create(['name' => 'Member Workspace']);

    $owner->currentAccount->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $owner->current_account_id])->save();

    $this->actingAs($member)
        ->get(route($routeName))
        ->assertOk();
})->with([
    'dashboard' => 'dashboard',
    'business overview' => 'business.overview',
    'roadmap' => 'roadmap',
    'banking' => 'banking-setup',
    'owner pay' => 'owner-pay',
    'guides' => 'guides.index',
    'growth' => 'grow',
]);

test('workspace members use account projects while only owner and admin manage sharing', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $project = Project::factory()->for($owner, 'owner')->create(['name' => 'Workspace Campaign']);

    $account->members()->attach($admin, ['role' => AccountRole::Admin]);
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $admin->forceFill(['current_account_id' => $account->id])->save();
    $member->forceFill(['current_account_id' => $account->id])->save();

    $this->actingAs($member)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee('Workspace Campaign');

    expect($member->can('update', $project))->toBeTrue()
        ->and($member->can('useAi', $project))->toBeTrue()
        ->and($member->can('share', $project))->toBeFalse()
        ->and($member->can('delete', $project))->toBeFalse()
        ->and($admin->can('share', $project))->toBeTrue()
        ->and($admin->can('delete', $project))->toBeTrue()
        ->and($owner->can('share', $project))->toBeTrue();
});

test('project guests keep project only access and cannot spend workspace AI', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create(['name' => 'Guest Project']);
    $project->sharedUsers()->attach($guest, ['permission' => ProjectPermission::Write]);

    $this->actingAs($guest)
        ->get(route('projects.show', $project))
        ->assertOk();

    Livewire::actingAs($guest)
        ->test(ProjectIndex::class)
        ->assertSee('Guest Project');

    expect($guest->can('view', $project))->toBeTrue()
        ->and($guest->can('update', $project))->toBeTrue()
        ->and($guest->can('useAi', $project))->toBeFalse()
        ->and($guest->can('share', $project))->toBeFalse()
        ->and($guest->can('delete', $project))->toBeFalse();

    $this->get(route('business.overview'))->assertRedirect(route('onboarding.welcome'));
});

test('advisor and generated kits are shared within the account and isolated from other accounts', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $otherOwner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();
    $otherBusiness = Business::factory()->for($otherOwner)->create();

    $owner->currentAccount->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $owner->current_account_id])->save();

    $conversation = AgentConversation::query()->create([
        'account_id' => $owner->current_account_id,
        'user_id' => $owner->id,
        'title' => 'Advisor Q&A',
    ]);
    $conversation->messages()->create([
        'user_id' => $owner->id,
        'agent' => 'advisor',
        'role' => 'assistant',
        'content' => 'Shared advisor answer',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);
    $otherConversation = AgentConversation::query()->create([
        'account_id' => $otherOwner->current_account_id,
        'user_id' => $otherOwner->id,
        'title' => 'Advisor Q&A',
    ]);
    $otherConversation->messages()->create([
        'user_id' => $otherOwner->id,
        'agent' => 'advisor',
        'role' => 'assistant',
        'content' => 'Private outsider answer',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $brandKit = BrandKit::factory()->forBusiness($business)->create([
        'user_id' => $owner->id,
        'name_ideas' => ['Shared Brand Name'],
    ]);
    $otherKit = BrandKit::factory()->forBusiness($otherBusiness)->create([
        'user_id' => $otherOwner->id,
        'name_ideas' => ['Private Brand Name'],
    ]);
    AdvertisingKit::factory()->forBusiness($business)->create([
        'user_id' => $owner->id,
        'ad_angles' => ['Shared Ad Angle'],
    ]);
    $otherAdvertisingKit = AdvertisingKit::factory()->forBusiness($otherBusiness)->create([
        'user_id' => $otherOwner->id,
        'ad_angles' => ['Private Ad Angle'],
    ]);

    Livewire::actingAs($member)
        ->test(History::class)
        ->assertSee('Shared advisor answer')
        ->assertDontSee('Private outsider answer');

    Livewire::actingAs($member)
        ->test(BrandingIndex::class)
        ->set('selectedKitId', $otherKit->id)
        ->assertSee('Shared Brand Name')
        ->assertDontSee('Private Brand Name');

    Livewire::actingAs($member)
        ->test(AdvertisingIndex::class)
        ->set('selectedKitId', $otherAdvertisingKit->id)
        ->assertSee('Shared Ad Angle')
        ->assertDontSee('Private Ad Angle');

    expect($brandKit->business_id)->toBe($business->id);
});
