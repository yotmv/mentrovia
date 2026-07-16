<?php

use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoProcessingStatus;
use App\Jobs\CleanupPhotoStorageObject;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\EraseUserAccountData;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoStorageCleanup;
use App\Models\Project;
use App\Models\User;
use App\Services\PhotoStorageCleanupService;
use App\Services\PhotoWorkReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    config([
        'photostudio.disk' => 's3',
    ]);
});

test('cleanup metadata and its database queue row roll back together', function () {
    config([
        'queue.connections.lifecycle-database.table' => 'missing_jobs_table',
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $photo = Photo::factory()->for($project)->for($user)->create();

    expect(fn () => DB::transaction(function () use ($photo): void {
        $photo->update(['processing_error' => 'generic-state-change']);
        app(PhotoStorageCleanupService::class)->track('s3', 'objects/private-source.jpg');
    }))->toThrow(RuntimeException::class);

    expect($photo->fresh()?->processing_error)->toBeNull()
        ->and(PhotoStorageCleanup::query()->where('path_hash', hash('sha256', 'objects/private-source.jpg'))->exists())->toBeFalse();
});

test('the bounded reconciler recovers lost cleanup erasure photo and batch dispatches', function () {
    Queue::fake();

    $user = User::factory()->create(['account_erasure_started_at' => now()]);
    $project = Project::factory()->for($user, 'owner')->create();
    $photo = Photo::factory()->for($project)->for($user)->create([
        'processing_status' => PhotoProcessingStatus::Failed,
        'derivatives_enqueued_at' => null,
    ]);
    $batch = PhotoGenerationBatch::factory()->for($project)->for($user)->create([
        'status' => GenerationBatchStatus::Pending,
        'analysis_enqueued_at' => null,
    ]);
    $cleanup = PhotoStorageCleanup::factory()->create([
        'completed_at' => null,
        'enqueued_at' => null,
    ]);

    $counts = app(PhotoWorkReconciler::class)->reconcile(10);

    expect($counts)->toBe(['cleanups' => 1, 'erasures' => 1, 'workspace_erasures' => 0, 'photos' => 1, 'descriptions' => 0, 'batches' => 1, 'slots' => 0]);
    Queue::assertPushed(CleanupPhotoStorageObject::class, fn ($job): bool => $job->cleanupId === $cleanup->id);
    Queue::assertPushed(EraseUserAccountData::class, fn ($job): bool => $job->userId === $user->id);
    Queue::assertPushed(GeneratePhotoDerivatives::class, fn ($job): bool => $job->photo->is($photo));
    Queue::assertPushed(RunPhotoGenerationBatch::class, fn ($job): bool => $job->generationBatch->is($batch));

    foreach (range(1, 50) as $staleWindow) {
        $this->travel(15)->minutes();
        expect(app(PhotoWorkReconciler::class)->reconcile(10))
            ->toBe(['cleanups' => 0, 'erasures' => 0, 'workspace_erasures' => 0, 'photos' => 0, 'descriptions' => 0, 'batches' => 0, 'slots' => 0]);
    }

    Queue::assertPushed(CleanupPhotoStorageObject::class, 1);
    Queue::assertPushed(EraseUserAccountData::class, 1);
    Queue::assertPushed(GeneratePhotoDerivatives::class, 1);
    Queue::assertPushed(RunPhotoGenerationBatch::class, 1);
});

test('reconciliation dispatch is bounded per work type', function () {
    Queue::fake();
    PhotoStorageCleanup::factory()->count(3)->create([
        'completed_at' => null,
        'enqueued_at' => null,
    ]);

    $counts = app(PhotoWorkReconciler::class)->reconcile(2);

    expect($counts['cleanups'])->toBe(2);
    Queue::assertPushed(CleanupPhotoStorageObject::class, 2);
});

test('reconciliation recovers a lost auto description dispatch', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $photo = Photo::factory()->uncaptioned()->for($project)->for($user)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
        'description_enqueued_at' => null,
    ]);

    $counts = app(PhotoWorkReconciler::class)->reconcile(10);

    expect($counts['descriptions'])->toBe(1);
    Queue::assertPushed(DescribeUploadedPhoto::class, fn ($job): bool => $job->photo->is($photo));
});

test('erasure and cleanup jobs have no finite retry cutoff', function () {
    expect(method_exists(EraseUserAccountData::class, 'retryUntil'))->toBeFalse()
        ->and(method_exists(CleanupPhotoStorageObject::class, 'retryUntil'))->toBeFalse();
});
