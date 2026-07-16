<?php

use App\Jobs\CleanupPhotoStorageObject;
use App\Models\PhotoStorageCleanup;
use App\Services\LifecycleRuntime;
use Illuminate\Support\Facades\DB;

test('lifecycle transport requires the same default database connection', function () {
    config()->set('queue.connections.lifecycle-database.connection', 'not-the-default');

    expect(fn () => app(LifecycleRuntime::class)->assertTransportReady())
        ->toThrow(RuntimeException::class, 'default database connection');
});

test('domain marker and database queue row roll back atomically', function () {
    $cleanup = PhotoStorageCleanup::factory()->create();
    $runtime = app(LifecycleRuntime::class);

    try {
        DB::transaction(function () use ($cleanup, $runtime): void {
            $cleanup->update(['enqueued_at' => now()]);
            $runtime->dispatch(new CleanupPhotoStorageObject($cleanup->id), $runtime->photoQueue());

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback');
    }

    expect($cleanup->refresh()->enqueued_at)->toBeNull()
        ->and(DB::table('jobs')->where('queue', 'photo-lifecycle')->count())->toBe(0);
});

test('health snapshot reports only queue metadata and scheduler age', function () {
    $runtime = app(LifecycleRuntime::class);
    $runtime->recordSchedulerHeartbeat();

    $snapshot = $runtime->healthSnapshot();

    expect($snapshot)->toHaveKeys(['scheduler_age_seconds', 'queues'])
        ->and($snapshot['queues'])->toHaveKeys(['security-erasure', 'photo-lifecycle'])
        ->and($snapshot['queues']['photo-lifecycle'])->toHaveKeys(['backlog', 'oldest_age_seconds']);
});

test('lifecycle health command passes with a fresh scheduler heartbeat and fails when stale', function () {
    $this->artisan('lifecycle:heartbeat')->assertSuccessful();
    $this->artisan('lifecycle:health')
        ->expectsOutputToContain('security-erasure: backlog=0 oldest=none')
        ->expectsOutputToContain('photo-lifecycle: backlog=0 oldest=none')
        ->assertSuccessful();

    $this->travel((int) config('photostudio.lifecycle.scheduler_heartbeat_max_age') + 1)->seconds();

    $this->artisan('lifecycle:health')->assertFailed();
});
