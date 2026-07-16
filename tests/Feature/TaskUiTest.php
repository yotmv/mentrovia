<?php

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('guests are redirected from the task list', function () {
    $this->get(route('tasks.index'))->assertRedirect(route('login'));
});

test('task list is scoped to the authenticated users business', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $business = Business::factory()->for($user)->create();
    $otherBusiness = Business::factory()->for($otherUser)->create();

    BusinessTask::factory()->for($business)->create([
        'title' => 'User visible task',
        'due_on' => now()->addDay(),
    ]);
    BusinessTask::factory()->for($otherBusiness)->create([
        'title' => 'Other user task',
        'due_on' => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->get(route('tasks.index', ['period' => 'all']))
        ->assertOk()
        ->assertSee('User visible task')
        ->assertDontSee('Other user task');
});

test('users can complete and uncomplete tasks with notes', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create();
    $task = BusinessTask::factory()->for($business)->create([
        'title' => 'Close monthly bookkeeping',
        'due_on' => now()->endOfMonth(),
    ]);

    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'completed' => '1',
            'notes' => 'Reconciled and reviewed.',
        ])
        ->assertRedirect();

    expect($task->refresh()->completed_at)->not->toBeNull()
        ->and($task->notes)->toBe('Reconciled and reviewed.')
        ->and($task->completions()->count())->toBe(1);

    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'completed' => '0',
            'notes' => 'Need to revisit.',
        ])
        ->assertRedirect();

    expect($task->refresh()->completed_at)->toBeNull()
        ->and($task->notes)->toBe('Need to revisit.')
        ->and($task->completions()->count())->toBe(1);

    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'completed' => false,
            'notes' => 'Still open.',
        ])
        ->assertRedirect();

    expect($task->refresh()->completed_at)->toBeNull()
        ->and($task->notes)->toBe('Still open.');

    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'completed' => '1',
            'notes' => 'Completed again.',
        ])
        ->assertRedirect();

    expect($task->completions()->count())->toBe(1)
        ->and($task->completions()->sole()->notes)->toBe('Reconciled and reviewed.');
});

test('users cannot update another users task', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Business::factory()->for($user)->create();
    $otherBusiness = Business::factory()->for($otherUser)->create();
    $task = BusinessTask::factory()->for($otherBusiness)->create();

    $this->actingAs($user)
        ->patch(route('tasks.update', $task), [
            'completed' => '1',
            'notes' => null,
        ])
        ->assertForbidden();
});

test('dashboard shows overdue tasks before upcoming incomplete tasks', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create(['name' => 'Bluebonnet Lawn Care']);

    BusinessTask::factory()->for($business)->create([
        'title' => 'Open upcoming task',
        'due_on' => now()->addDay(),
        'completed_at' => null,
    ]);
    BusinessTask::factory()->for($business)->create([
        'title' => 'Open overdue task',
        'due_on' => now()->subDay(),
        'completed_at' => null,
    ]);
    BusinessTask::factory()->for($business)->create([
        'title' => 'Completed future task',
        'due_on' => now()->addDays(2),
        'completed_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Due and upcoming tasks')
        ->assertSee('Overdue')
        ->assertSeeInOrder(['Open overdue task', 'Open upcoming task'])
        ->assertDontSee('Completed future task');
});

test('task periods include incomplete overdue work once alongside current tasks', function (string $period) {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create();

    BusinessTask::factory()->for($business)->create([
        'title' => 'Incomplete overdue task',
        'due_on' => now()->subWeek(),
        'completed_at' => null,
    ]);
    BusinessTask::factory()->for($business)->create([
        'title' => 'Current week task',
        'due_on' => now(),
        'completed_at' => null,
    ]);
    BusinessTask::factory()->for($business)->create([
        'title' => 'Completed overdue task',
        'due_on' => now()->subYear(),
        'completed_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('tasks.index', ['period' => $period]))
        ->assertOk()
        ->assertSee('Incomplete overdue task')
        ->assertSee('Current week task')
        ->assertSee('Overdue')
        ->assertDontSee('Completed overdue task');

    expect(substr_count($response->getContent(), 'Incomplete overdue task'))->toBe(1);
})->with(['week', 'month', 'quarter', 'year']);
