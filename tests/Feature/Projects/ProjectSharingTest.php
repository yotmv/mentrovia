<?php

use App\Enums\ProjectPermission;
use App\Livewire\Projects\Show;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    config(['photostudio.disk' => 's3']);
    Notification::fake();
});

test('a non-member cannot view a project', function () {
    $project = Project::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('projects.show', $project))->assertForbidden();
});

test('the owner can invite a recipient with read or write permission', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create(['email' => 'teammate@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'teammate@example.com')
        ->set('sharePermission', 'write')
        ->call('share')
        ->assertHasNoErrors();

    $invitation = $project->invitations()->sole();

    expect($invitation->email)->toBe($teammate->email)
        ->and($invitation->permission)->toBe(ProjectPermission::Write)
        ->and($project->sharedUsers()->count())->toBe(0)
        ->and($project->isEditableBy($teammate))->toBeFalse();
});

test('sharing with an unknown email gives the same response as an eligible account', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create(['email' => 'teammate@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    $unknownAccountResponse = Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'nobody@example.com')
        ->call('share')
        ->assertHasNoErrors();

    $eligibleAccountResponse = Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'teammate@example.com')
        ->call('share')
        ->assertHasNoErrors();

    $invitations = $project->invitations()->orderBy('email')->get();

    expect($unknownAccountResponse->effects['dispatches'])->toEqual($eligibleAccountResponse->effects['dispatches'])
        ->and($project->sharedUsers()->count())->toBe(0)
        ->and($invitations)->toHaveCount(2)
        ->and($invitations->map->only(['permission', 'accepted_at', 'revoked_at'])->unique()->count())->toBe(1)
        ->and(ProjectInvitation::query()->where('email', $teammate->email)->exists())->toBeTrue();
});

test('sharing with the owner gives the same generic response without changing access', function () {
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'owner@example.com')
        ->call('share')
        ->assertHasNoErrors();

    expect($project->sharedUsers()->count())->toBe(0);
});

test('the owner can unshare a project', function () {
    $owner = User::factory()->create();
    $teammate = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->sharedUsers()->attach($teammate->id, ['permission' => 'read']);
    $this->actingAs($owner);

    Livewire::test(Show::class, ['project' => $project])
        ->call('unshare', $teammate->id);

    expect($project->sharedUsers()->count())->toBe(0);
});

test('a read-permission user can view but not upload, generate, or share', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->sharedUsers()->attach($viewer->id, ['permission' => ProjectPermission::Read->value]);
    $this->actingAs($viewer);

    $this->get(route('projects.show', $project))->assertOk();

    Livewire::test(Show::class, ['project' => $project])
        ->call('generate')
        ->assertForbidden();

    Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'x@example.com')
        ->call('share')
        ->assertForbidden();
});

test('a write-permission user can generate but not share or delete', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $editor = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->sharedUsers()->attach($editor->id, ['permission' => ProjectPermission::Write->value]);

    expect($project->isEditableBy($editor))->toBeTrue()
        ->and($editor->can('update', $project))->toBeTrue()
        ->and($editor->can('share', $project))->toBeFalse()
        ->and($editor->can('delete', $project))->toBeFalse();
});
