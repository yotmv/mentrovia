<?php

use App\Models\Business;
use App\Models\RecurringTaskTemplate;
use App\Services\RecurringTaskGenerator;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('rollover command advances completed tasks across businesses in bounded chunks', function () {
    Carbon::setTestNow('2026-07-08 10:00:00');
    RecurringTaskTemplate::factory()->create([
        'slug' => 'monthly-rollover-test',
        'due_rule' => ['type' => 'end_of_month'],
    ]);
    $businesses = Business::factory()->count(2)->create();
    $generator = app(RecurringTaskGenerator::class);

    foreach ($businesses as $business) {
        $task = $generator->generateFor($business)->sole();
        $task->forceFill(['completed_at' => now(), 'notes' => 'Complete.'])->save();
        $task->completions()->create([
            'business_id' => $business->id,
            'completed_for' => $task->due_on,
            'completed_at' => now(),
            'notes' => 'Complete.',
        ]);
    }

    Carbon::setTestNow('2026-08-01 10:00:00');

    $this->artisan('tasks:rollover', ['--chunk' => 1])
        ->expectsOutputToContain('2 businesses processed.')
        ->assertSuccessful();

    foreach ($businesses as $business) {
        $task = $business->tasks()->sole();

        expect($task->due_on?->toDateString())->toBe('2026-08-31')
            ->and($task->completed_at)->toBeNull()
            ->and($task->notes)->toBeNull()
            ->and($task->completions()->count())->toBe(1);
    }
});
