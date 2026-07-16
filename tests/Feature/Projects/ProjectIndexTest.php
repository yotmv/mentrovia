<?php

use App\Livewire\Projects\Index;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('the projects page renders for authenticated users', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('projects.index'))->assertOk()->assertSee('Photo Projects');
});

test('a user can create a project and is redirected to it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('name', 'Storefront refresh')
        ->set('projectDate', '2026-07-01')
        ->call('createProject')
        ->assertHasNoErrors();

    $project = $user->projects()->sole();

    expect($project->name)->toBe('Storefront refresh')
        ->and($project->project_date->format('Y-m-d'))->toBe('2026-07-01');
});

test('a campaign photo brief can be carried into a new project without creating a separate relationship', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('photoBrief', 'Warm editorial product image with a clear local-business point of view.')
        ->set('name', 'Campaign refresh')
        ->set('projectDate', '2026-07-01')
        ->call('createProject')
        ->assertHasNoErrors();

    $project = $user->projects()->sole();

    expect($project->name)->toBe('Campaign refresh');
});

test('projects can be searched by name', function () {
    $user = User::factory()->create();
    Project::factory()->for($user, 'owner')->create(['name' => 'Kitchen remodel photos']);
    Project::factory()->for($user, 'owner')->create(['name' => 'Bathroom tiles']);
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('search', 'Kitchen')
        ->assertSee('Kitchen remodel photos')
        ->assertDontSee('Bathroom tiles');
});

test('projects can be searched by date', function () {
    $user = User::factory()->create();
    Project::factory()->for($user, 'owner')->create(['name' => 'March batch', 'project_date' => '2026-03-15']);
    Project::factory()->for($user, 'owner')->create(['name' => 'June batch', 'project_date' => '2026-06-20']);
    $this->actingAs($user);

    Livewire::test(Index::class)
        ->set('search', '2026-03-15')
        ->assertSee('March batch')
        ->assertDontSee('June batch');
});

test('only owned and shared projects are listed', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    Project::factory()->for($user, 'owner')->create(['name' => 'My own project']);
    $shared = Project::factory()->for($stranger, 'owner')->create(['name' => 'Shared with me']);
    $shared->sharedUsers()->attach($user->id, ['permission' => 'read']);
    Project::factory()->for($stranger, 'owner')->create(['name' => 'Private stranger project']);

    $this->actingAs($user);

    Livewire::test(Index::class)
        ->assertSee('My own project')
        ->assertSee('Shared with me')
        ->assertDontSee('Private stranger project');
});
