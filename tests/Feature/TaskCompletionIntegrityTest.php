<?php

use App\Models\Business;
use App\Models\BusinessTask;
use App\Models\TaskCompletion;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

test('a task has only one immutable completion record for a due period', function () {
    $business = Business::factory()->create();
    $task = BusinessTask::factory()->for($business)->create();
    $completedFor = now()->toDateString();

    TaskCompletion::factory()->for($task, 'task')->for($business)->create([
        'completed_for' => $completedFor,
    ]);

    expect(fn () => TaskCompletion::factory()->for($task, 'task')->for($business)->create([
        'completed_for' => $completedFor,
    ]))->toThrow(QueryException::class);
});

test('the completion uniqueness migration refuses ambiguous legacy duplicates', function () {
    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'task_completion_migration_preflight';

    config([
        "database.connections.{$isolatedConnection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($isolatedConnection);
    DB::setDefaultConnection($isolatedConnection);
    DB::connection()->getSchemaBuilder()->create('task_completions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('business_task_id');
        $table->date('completed_for');
    });
    DB::table('task_completions')->insert([
        ['business_task_id' => 42, 'completed_for' => '2026-07-31'],
        ['business_task_id' => 42, 'completed_for' => '2026-07-31'],
    ]);

    $migration = require database_path('migrations/2026_07_15_050153_add_unique_period_to_task_completions_table.php');

    try {
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'duplicate task:period pairs [42:2026-07-31]');
    } finally {
        DB::disconnect($isolatedConnection);
        DB::purge($isolatedConnection);
        DB::setDefaultConnection($originalConnection);
    }
});
