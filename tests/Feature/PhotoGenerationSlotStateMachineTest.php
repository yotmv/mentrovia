<?php

use App\Ai\Images\ImageModelCatalog;
use App\Enums\PhotoCostSource;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Jobs\GeneratePhotoWithModel;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\Project;
use App\Models\User;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoGenerationSlotService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Livewire\Livewire;

test('a generation slot is enqueued once across repeated scheduler windows', function () {
    Queue::fake();

    $batch = PhotoGenerationBatch::factory()->create();
    $models = [['provider' => 'openrouter', 'model' => 'google/gemini-2.5-flash-image']];
    $service = app(PhotoGenerationSlotService::class);

    foreach (range(1, 50) as $window) {
        $service->createAndEnqueue($batch, $models, PhotoMode::Cleanup);
    }

    $slot = PhotoGenerationSlot::query()->sole();

    expect($slot->status)->toBe(PhotoGenerationSlotStatus::Queued)
        ->and($slot->enqueued_at)->not->toBeNull()
        ->and($slot->operation_uuid)->not->toBeEmpty();

    Queue::assertPushed(GeneratePhotoWithModel::class, 1);
    Queue::assertPushed(
        GeneratePhotoWithModel::class,
        fn (GeneratePhotoWithModel $job): bool => $job->slotId === $slot->id
            && $job->connection === 'lifecycle-database'
            && $job->queue === 'photo-lifecycle',
    );
});

test('an expired pre-provider claim resumes with a new fence and rejects the stale token', function () {
    $slot = PhotoGenerationSlot::factory()->create([
        'status' => PhotoGenerationSlotStatus::Queued,
        'enqueued_at' => now(),
    ]);
    $service = app(PhotoGenerationSlotService::class);

    $first = $service->claim($slot->id);

    expect($first)->not->toBeNull()
        ->and($service->claim($slot->id))->toBeNull();

    $this->travel((int) config('photostudio.lifecycle.claim_seconds') + 1)->seconds();

    $second = $service->claim($slot->id);

    expect($second)->not->toBeNull()
        ->and($second?->executionToken)->not->toBe($first?->executionToken)
        ->and($second?->fence)->toBe(($first?->fence ?? 0) + 1)
        ->and($service->markProviderStarted($first))->toBeFalse()
        ->and($service->markProviderStarted($second))->toBeTrue();
});

test('a provider-started worker loss becomes ambiguous and cannot be reclaimed', function () {
    $slot = PhotoGenerationSlot::factory()->create([
        'status' => PhotoGenerationSlotStatus::ProviderStarted,
        'execution_token' => fake()->uuid(),
        'fence' => 3,
        'enqueued_at' => now(),
        'provider_started_at' => now()->subMinutes(20),
    ]);

    $claim = app(PhotoGenerationSlotService::class)->claim($slot->id);

    expect($claim)->toBeNull()
        ->and($slot->refresh()->status)->toBe(PhotoGenerationSlotStatus::Ambiguous)
        ->and($slot->failure_code)->toBe('provider_started_worker_lost')
        ->and($slot->manual_review_at)->not->toBeNull();
});

test('a staged slot can resume finalization without returning to provider-started', function () {
    $slot = PhotoGenerationSlot::factory()->create([
        'status' => PhotoGenerationSlotStatus::Staged,
        'execution_token' => fake()->uuid(),
        'fence' => 2,
        'enqueued_at' => now(),
        'provider_started_at' => now(),
        'staged_disk' => 'local',
        'staged_path' => 'generated_/staging/result.png',
    ]);

    $claim = app(PhotoGenerationSlotService::class)->claim($slot->id);

    expect($claim)->not->toBeNull()
        ->and($claim?->resumeStaged)->toBeTrue()
        ->and($slot->refresh()->status)->toBe(PhotoGenerationSlotStatus::Staged)
        ->and($slot->fence)->toBe(3);
});

test('an uncertain photo commit reuses the exact referenced staged object without another provider call', function () {
    Storage::fake('s3');
    Queue::fake();
    $providerCalls = 0;
    Image::fake(function () use (&$providerCalls): string {
        $providerCalls++;

        return base64_encode('unexpected-provider-call');
    });

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($owner)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [$input->id],
        'analysis' => ['group_prompt' => 'Known committed prompt'],
    ]);
    $path = 'generated_/staging/known-operation/original.png';
    Storage::disk('s3')->put($path, 'committed-image');
    $slot = PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create([
        'status' => PhotoGenerationSlotStatus::Staged,
        'enqueued_at' => now(),
        'staged_disk' => 's3',
        'staged_path' => $path,
    ]);
    $photo = Photo::query()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'photo_generation_batch_id' => $batch->id,
        'kind' => PhotoKind::Generated,
        'disk' => 's3',
        'path' => $path,
        'processing_status' => PhotoProcessingStatus::Pending,
        'text' => 'Known committed prompt',
        'text_source' => PhotoTextSource::Auto,
        'provider' => $slot->provider,
        'model' => $slot->model,
        'mode' => PhotoMode::Cleanup,
    ]);

    (new GeneratePhotoWithModel($slot->id))->handle(
        app(ImageModelCatalog::class),
        app(PhotoGenerationLifecycle::class),
    );

    expect($providerCalls)->toBe(0)
        ->and($slot->refresh()->status)->toBe(PhotoGenerationSlotStatus::Completed)
        ->and($slot->photo_id)->toBe($photo->id)
        ->and($batch->generatedPhotos()->count())->toBe(1);
    Storage::disk('s3')->assertExists($path);
});

test('a staged provider-billed result preserves its cost source when finalization resumes', function () {
    Storage::fake('s3');
    Queue::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($owner)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [$input->id],
        'analysis' => ['group_prompt' => 'Finalize the staged result.'],
    ]);
    $path = 'generated_/staging/provider-cost/original.png';
    Storage::disk('s3')->put($path, 'committed-image');
    $slot = PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create([
        'status' => PhotoGenerationSlotStatus::Staged,
        'enqueued_at' => now(),
        'staged_disk' => 's3',
        'staged_path' => $path,
        'actual_provider' => 'openrouter',
        'actual_model' => 'google/gemini-2.5-flash-image',
        'actual_cost_usd' => 0.42,
        'actual_cost_source' => PhotoCostSource::Provider,
    ]);

    (new GeneratePhotoWithModel($slot->id))->handle(
        app(ImageModelCatalog::class),
        app(PhotoGenerationLifecycle::class),
    );

    $photo = $batch->generatedPhotos()->sole();

    expect($slot->refresh()->status)->toBe(PhotoGenerationSlotStatus::Completed)
        ->and((float) $photo->cost_usd)->toBe(0.42)
        ->and($photo->cost_source)->toBe(PhotoCostSource::Provider);
});

test('the lifecycle boundary rejects duplicate typed and oversized batch input ids', function (array $inputIds) {
    config(['photostudio.max_batch_inputs' => 2]);

    $batch = PhotoGenerationBatch::factory()->create(['input_photo_ids' => $inputIds]);

    expect(fn () => app(PhotoGenerationLifecycle::class)->validatedBatchInputPhotos($batch))
        ->toThrow(RuntimeException::class, 'invalid or oversized');
})->with([
    'duplicates' => [[1, 1]],
    'string id' => [['1']],
    'non-list' => [[1 => 1]],
    'oversized' => [[1, 2, 3]],
]);

test('the lifecycle and livewire boundaries reject foreign and excessive selected photos', function () {
    config(['photostudio.max_batch_inputs' => 2]);

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $owned = Photo::factory()->count(3)->for($project)->for($owner)->create();
    $foreign = Photo::factory()->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [$owned[0]->id, $foreign->id],
    ]);

    expect(fn () => app(PhotoGenerationLifecycle::class)->validatedBatchInputPhotos($batch))
        ->toThrow(RuntimeException::class, 'missing or unowned');

    Livewire::actingAs($owner)
        ->test('projects.show', ['project' => $project])
        ->set('selectedPhotoIds', $owned->pluck('id')->all())
        ->call('generate')
        ->assertHasErrors('selectedPhotoIds');

    expect($project->generationBatches()->count())->toBe(1);
});

test('the legacy input preflight refuses oversized json before bounded code deploys', function () {
    $originalConnection = DB::getDefaultConnection();
    $isolated = 'legacy_batch_input_preflight';

    config([
        'photostudio.max_batch_inputs' => 2,
        "database.connections.{$isolated}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);
    DB::purge($isolated);
    DB::setDefaultConnection($isolated);
    DB::connection()->getSchemaBuilder()->create('photo_generation_batches', function (Blueprint $table): void {
        $table->id();
        $table->json('input_photo_ids');
    });
    DB::table('photo_generation_batches')->insert(['input_photo_ids' => json_encode([1, 2, 3])]);
    $migration = require database_path('migrations/2026_07_15_025733_preflight_photo_generation_batch_input_limits.php');

    try {
        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'exceed the configured 2-input bound');
    } finally {
        DB::disconnect($isolated);
        DB::purge($isolated);
        DB::setDefaultConnection($originalConnection);
    }
});
