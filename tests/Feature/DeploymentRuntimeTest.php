<?php

use App\Jobs\CleanupPhotoStorageObject;
use App\Jobs\EraseUserAccountData;
use App\Jobs\GeneratePhotoWithModel;

test('queue retry windows exceed Photo Studio job and worker timeouts', function () {
    $job = new GeneratePhotoWithModel(123);

    expect($job->timeout)->toBeGreaterThan(300)
        ->and($job->tries)->toBe(0)
        ->and(config('queue.connections.lifecycle-database.retry_after'))->toBeGreaterThan($job->timeout)
        ->and(config('queue.connections.lifecycle-database.driver'))->toBe('database')
        ->and(config('queue.connections.lifecycle-database.connection'))->toBe(config('database.default'))
        ->and(config('photostudio.operation_lease_seconds'))->toBeGreaterThan($job->timeout);
});

test('durable erasure and object cleanup jobs keep retrying transient failures', function () {
    $erasure = new EraseUserAccountData(123);
    $cleanup = new CleanupPhotoStorageObject(456);

    expect($erasure->tries)->toBe(0)
        ->and(method_exists($erasure, 'retryUntil'))->toBeFalse()
        ->and($cleanup->tries)->toBe(0)
        ->and(method_exists($cleanup, 'retryUntil'))->toBeFalse();
});
