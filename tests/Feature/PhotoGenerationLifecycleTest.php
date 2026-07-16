<?php

use App\Ai\Agents\PhotoBatchAnalyst;
use App\Ai\Images\ImageModelCatalog;
use App\Ai\Images\ImageModelChooser;
use App\Enums\AccountRole;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoMode;
use App\Images\PhotoDerivativeService;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\GeneratePhotoWithModel;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\Project;
use App\Models\User;
use App\Services\LifecycleRuntime;
use App\Services\PhotoGenerationLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;
use Livewire\Livewire;

function lifecycleGenerationJob(
    PhotoGenerationBatch $batch,
    string $provider = 'openrouter',
    string $model = 'google/gemini-2.5-flash-image',
    PhotoMode $mode = PhotoMode::Cleanup,
): GeneratePhotoWithModel {
    $slot = PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create([
        'provider' => $provider,
        'model' => $model,
        'mode' => $mode,
        'status' => PhotoGenerationSlotStatus::Queued,
        'enqueued_at' => now(),
    ]);

    return new GeneratePhotoWithModel($slot->id);
}

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');

    config([
        'photostudio.disk' => 's3',
        'ai.providers.openrouter.key' => 'test-key',
    ]);
});

test('an ordinary workspace member can acquire and use a project lifecycle lease', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->members()->attach($member, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $lifecycle = app(PhotoGenerationLifecycle::class);

    $lease = $lifecycle->acquireForProject($project, $member, 'workspace-member-operation');

    expect($lease)->not->toBeNull()
        ->and($lifecycle->leaseIsUsable($lease))->toBeTrue();
});

test('removing the initiating project creator from the workspace invalidates an issued lease', function () {
    $workspaceOwner = User::factory()->create();
    $projectCreator = User::factory()->create();
    $account = $workspaceOwner->currentAccount()->sole();
    $account->members()->attach($projectCreator, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($projectCreator, 'owner')->create(['account_id' => $account->id]);
    $lifecycle = app(PhotoGenerationLifecycle::class);
    $lease = $lifecycle->acquireForProject($project, $projectCreator, 'creator-operation');

    expect($lease)->not->toBeNull();

    $account->members()->detach($projectCreator);

    expect($lifecycle->leaseIsUsable($lease))->toBeFalse();
});

test('an explicit write guest retains project lifecycle access without workspace membership', function () {
    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $project->sharedUsers()->attach($guest, ['permission' => 'write']);
    $lifecycle = app(PhotoGenerationLifecycle::class);

    $lease = $lifecycle->acquireForProject($project, $guest, 'guest-project-operation');

    expect($lease)->not->toBeNull()
        ->and($lifecycle->leaseIsUsable($lease))->toBeTrue()
        ->and($account->members()->whereKey($guest->id)->exists())->toBeFalse();
});

test('queued image jobs serialize only a slot id and never the customer prompt', function () {

    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $secretPrompt = 'PRIVATE-PROMPT-'.fake()->uuid();
    $batch = PhotoGenerationBatch::factory()
        ->for($project)
        ->for($user)
        ->create(['analysis' => ['group_prompt' => $secretPrompt]]);
    $slot = PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create();

    DB::transaction(function () use ($slot): void {
        app(LifecycleRuntime::class)->dispatch(
            new GeneratePhotoWithModel($slot->id),
            'photo-lifecycle',
        );
    });

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)
        ->not->toContain($secretPrompt)
        ->and(serialize(new GeneratePhotoWithModel($slot->id)))->not->toContain($secretPrompt);
});

test('a lifecycle marker prevents a queued job from contacting the provider or storing a generated photo', function () {
    Image::fake();

    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $input = Photo::factory()->for($project)->for($user)->create();
    $batch = PhotoGenerationBatch::factory()
        ->for($project)
        ->for($user)
        ->create([
            'input_photo_ids' => [$input->id],
            'analysis' => ['group_prompt' => 'Do not run this prompt'],
        ]);

    $lifecycle = app(PhotoGenerationLifecycle::class);
    $lifecycle->beginAccountErasure($user);

    lifecycleGenerationJob($batch)->handle(app(ImageModelCatalog::class), $lifecycle);

    Image::assertNothingGenerated();
    expect($batch->generatedPhotos()->exists())->toBeFalse()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

test('an erasure marker that wins while the provider is running blocks the final object write', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($owner)->create();
    Storage::disk('s3')->put($input->path, 'input-image');

    $batch = PhotoGenerationBatch::factory()
        ->for($project)
        ->for($owner)
        ->create([
            'input_photo_ids' => [$input->id],
            'analysis' => ['group_prompt' => 'Private countertop details'],
        ]);

    $lifecycle = app(PhotoGenerationLifecycle::class);
    $validPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=';

    Http::fake(function (Request $request) use ($lifecycle, $owner, $validPng) {
        $lifecycle->beginAccountErasure($owner);

        return Http::response([
            'choices' => [[
                'message' => [
                    'images' => [[
                        'image_url' => ['url' => 'data:image/png;base64,'.$validPng],
                    ]],
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'cost' => 0.01],
        ]);
    });

    $job = lifecycleGenerationJob($batch, model: 'black-forest-labs/flux.2-pro');
    $job->handle(app(ImageModelCatalog::class), $lifecycle);

    expect($batch->generatedPhotos()->exists())->toBeFalse()
        ->and(PhotoGenerationSlot::findOrFail($job->slotId)->status)->toBe(PhotoGenerationSlotStatus::Ambiguous)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(2);
});

test('the project owner lifecycle marker also cancels a collaborators batch', function () {
    Image::fake();

    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($collaborator)->create();
    $batch = PhotoGenerationBatch::factory()
        ->for($project)
        ->for($collaborator)
        ->create([
            'input_photo_ids' => [$input->id],
            'analysis' => ['group_prompt' => 'Do not run this collaborator prompt'],
        ]);

    $lifecycle = app(PhotoGenerationLifecycle::class);
    $lifecycle->beginAccountErasure($owner);

    lifecycleGenerationJob($batch)->handle(app(ImageModelCatalog::class), $lifecycle);

    Image::assertNothingGenerated();
    expect($batch->generatedPhotos()->exists())->toBeFalse();
});

test('batch creation protects selected input owners from an erasure race', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $inputOwner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($inputOwner)->create();
    app(PhotoGenerationLifecycle::class)->beginAccountErasure($inputOwner);

    Livewire::actingAs($owner)
        ->test('projects.show', ['project' => $project])
        ->call('toggleSelection', $input->id)
        ->call('generate')
        ->assertHasErrors('selectedPhotoIds');

    expect($project->generationBatches()->exists())->toBeFalse();
    Queue::assertNotPushed(RunPhotoGenerationBatch::class);
});

test('the lifecycle marker prevents a queued derivative job from writing objects', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $photo = Photo::factory()->for($project)->for($user)->create();

    $lifecycle = app(PhotoGenerationLifecycle::class);
    $lifecycle->beginAccountErasure($user);

    $service = Mockery::mock(PhotoDerivativeService::class);
    $service->shouldNotReceive('process');

    (new GeneratePhotoDerivatives($photo))->handle($service, $lifecycle);

    expect($photo->fresh()?->derivatives)->toBeNull()
        ->and(Storage::disk('s3')->allFiles())->toBeEmpty();
});

test('revoking a collaborators workspace and project access after enqueue prevents the provider call', function () {
    Image::fake();

    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->account->members()->attach($collaborator, ['role' => AccountRole::Member->value]);
    $project->sharedUsers()->attach($collaborator, ['permission' => 'write']);
    $input = Photo::factory()->for($project)->for($collaborator)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($collaborator)->create([
        'input_photo_ids' => [$input->id],
        'analysis' => ['group_prompt' => 'This should never leave the application'],
    ]);

    $project->sharedUsers()->detach($collaborator);
    $project->account->members()->detach($collaborator);

    lifecycleGenerationJob($batch)->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

    Image::assertNothingGenerated();
    expect($batch->generatedPhotos()->exists())->toBeFalse();
});

test('revoking workspace and project access during a provider call prevents final storage and persistence', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $collaborator = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->account->members()->attach($collaborator, ['role' => AccountRole::Member->value]);
    $project->sharedUsers()->attach($collaborator, ['permission' => 'write']);
    $input = Photo::factory()->for($project)->for($collaborator)->create();
    Storage::disk('s3')->put($input->path, 'input-image');
    $batch = PhotoGenerationBatch::factory()->for($project)->for($collaborator)->create([
        'input_photo_ids' => [$input->id],
        'analysis' => ['group_prompt' => 'Recreate private customer details'],
    ]);
    $validPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII=';

    Http::fake(function () use ($project, $collaborator, $validPng) {
        $project->sharedUsers()->detach($collaborator);
        $project->account->members()->detach($collaborator);

        return Http::response([
            'choices' => [[
                'message' => [
                    'images' => [[
                        'image_url' => ['url' => 'data:image/png;base64,'.$validPng],
                    ]],
                ],
            ]],
            'usage' => ['cost' => 0.01],
        ]);
    });

    $job = lifecycleGenerationJob($batch, model: 'black-forest-labs/flux.2-pro');
    $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

    expect($batch->generatedPhotos()->exists())->toBeFalse()
        ->and(PhotoGenerationSlot::findOrFail($job->slotId)->status)->toBe(PhotoGenerationSlotStatus::Ambiguous)
        ->and(Storage::disk('s3')->allFiles())->toHaveCount(2);
});

test('retrying the same generation slot does not create duplicate photos', function () {
    $providerCalls = 0;
    Image::fake(function () use (&$providerCalls): string {
        $providerCalls++;

        return base64_encode('fake-image-content');
    });
    Queue::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($owner)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [$input->id],
        'analysis' => ['group_prompt' => 'Idempotent generation'],
    ]);
    $job = lifecycleGenerationJob($batch);

    $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));
    $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

    expect($batch->generatedPhotos()->count())->toBe(1)
        ->and($providerCalls)->toBe(1);
});

test('the generation uniqueness migration refuses duplicate upgrade data', function () {
    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'photo_generation_migration_preflight';

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
    DB::connection()->getSchemaBuilder()->create('photos', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('photo_generation_batch_id')->nullable();
        $table->string('provider')->nullable();
        $table->string('model')->nullable();
    });
    DB::table('photos')->insert([
        ['photo_generation_batch_id' => 12, 'provider' => 'openrouter', 'model' => 'safe-model'],
        ['photo_generation_batch_id' => 12, 'provider' => 'openrouter', 'model' => 'safe-model'],
    ]);

    $migration = require database_path('migrations/2026_07_15_013226_add_generation_idempotency_index_to_photos_table.php');

    try {
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'duplicate batch/provider/model slots [12:openrouter:safe-model]');
    } finally {
        DB::disconnect($isolatedConnection);
        DB::purge($isolatedConnection);
        DB::setDefaultConnection($originalConnection);
    }
});

test('erasure beginning during batch analysis prevents model selection and fan out', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $input = Photo::factory()->for($project)->for($owner)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [$input->id],
    ]);
    $lifecycle = app(PhotoGenerationLifecycle::class);

    PhotoBatchAnalyst::fake(function () use ($lifecycle, $owner): array {
        $lifecycle->beginAccountErasure($owner);

        return [
            'group_prompt' => 'Private analysis result',
            'images' => [],
        ];
    });

    (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), $lifecycle);

    PhotoBatchAnalyst::assertPrompted(fn ($prompt): bool => $prompt->contains('Analyze the 1 attached photos'));
    Queue::assertNotPushed(GeneratePhotoWithModel::class);
    expect($batch->fresh()->analysis)->toBeNull();
});
