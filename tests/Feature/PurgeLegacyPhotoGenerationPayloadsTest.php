<?php

use App\Jobs\GeneratePhotoWithModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'queue.default' => 'database',
        'queue.failed.driver' => 'database-uuids',
        'queue.failed.database' => config('database.default'),
    ]);
});

function queuedPayload(string $displayName, string $serializedCommand): string
{
    return (string) json_encode([
        'displayName' => $displayName,
        'data' => ['command' => $serializedCommand],
    ]);
}

function insertPendingPayload(string $payload): int
{
    return (int) DB::table('jobs')->insertGetId([
        'queue' => 'default',
        'payload' => $payload,
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
}

function insertFailedPayload(string $payload, string $exception): int
{
    return (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => fake()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload,
        'exception' => $exception,
        'failed_at' => now(),
    ]);
}

test('legacy prompt-bearing pending jobs and all photo generation failures are purged', function () {
    $currentJob = new GeneratePhotoWithModel(123);

    $legacyPendingIds = collect(range(1, 105))->map(fn (): int => insertPendingPayload(queuedPayload(
        GeneratePhotoWithModel::class,
        'O:31:"App\\Jobs\\GeneratePhotoWithModel":1:{s:6:"prompt";s:22:"private customer text";}',
    )));
    $currentPendingId = insertPendingPayload(queuedPayload(GeneratePhotoWithModel::class, serialize($currentJob)));
    $unrelatedPendingId = insertPendingPayload(queuedPayload('App\\Jobs\\UnrelatedJob', 'unrelated'));
    $wrapperPendingId = insertPendingPayload(queuedPayload(
        'App\\Jobs\\WrapperJob',
        GeneratePhotoWithModel::class.' s:6:"prompt"; private customer text',
    ));
    $failedPhotoId = insertFailedPayload(
        queuedPayload(GeneratePhotoWithModel::class, serialize($currentJob)),
        'private provider response content',
    );
    $unrelatedFailedId = insertFailedPayload(queuedPayload('App\\Jobs\\UnrelatedJob', 'unrelated'), 'ordinary failure');

    $this->artisan('security:purge-legacy-photo-generation-payloads --workers-stopped')
        ->expectsOutputToContain('Purged 105 legacy pending payload(s) and 1 photo-generation failed record(s).')
        ->assertSuccessful();

    expect(DB::table('jobs')->whereIn('id', $legacyPendingIds)->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->where('id', $failedPhotoId)->exists())->toBeFalse()
        ->and(DB::table('jobs')->where('id', $currentPendingId)->exists())->toBeTrue()
        ->and(DB::table('jobs')->where('id', $unrelatedPendingId)->exists())->toBeTrue()
        ->and(DB::table('jobs')->where('id', $wrapperPendingId)->exists())->toBeTrue()
        ->and(DB::table('failed_jobs')->where('id', $unrelatedFailedId)->exists())->toBeTrue();
});

test('dry run reports sensitive records without deleting them', function () {
    $legacyPendingId = insertPendingPayload(queuedPayload(
        GeneratePhotoWithModel::class,
        'O:31:"App\\Jobs\\GeneratePhotoWithModel":1:{s:6:"prompt";s:22:"private customer text";}',
    ));
    $failedPhotoId = insertFailedPayload(
        queuedPayload(GeneratePhotoWithModel::class, 'serialized'),
        'private provider response content',
    );

    $this->artisan('security:purge-legacy-photo-generation-payloads --dry-run')
        ->expectsOutputToContain('Found 1 legacy pending payload(s) and 1 photo-generation failed record(s).')
        ->assertSuccessful();

    expect(DB::table('jobs')->where('id', $legacyPendingId)->exists())->toBeTrue()
        ->and(DB::table('failed_jobs')->where('id', $failedPhotoId)->exists())->toBeTrue();
});

test('destructive purge requires stopped workers', function () {
    $this->artisan('security:purge-legacy-photo-generation-payloads')
        ->expectsOutputToContain('Stop queue producers and workers')
        ->assertExitCode(Command::INVALID);
});

test('database failed jobs are purged even when pending jobs use redis', function () {
    $failedPhotoId = insertFailedPayload(
        queuedPayload(GeneratePhotoWithModel::class, 'serialized'),
        'private provider response content',
    );

    config(['queue.default' => 'redis']);

    $this->artisan('security:purge-legacy-photo-generation-payloads --workers-stopped')
        ->expectsOutputToContain('Pending jobs use a non-database backend and were not scanned')
        ->expectsOutputToContain('Purged 0 legacy pending payload(s) and 1 photo-generation failed record(s).')
        ->assertExitCode(Command::INVALID);

    expect(DB::table('failed_jobs')->where('id', $failedPhotoId)->exists())->toBeFalse();
});
