<?php

use App\Ai\Agents\PhotoBatchAnalyst;
use App\Ai\Agents\PhotoDescriber;
use App\Ai\Images\ImageModelCatalog;
use App\Ai\Images\ImageModelChooser;
use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\GeneratePhotoWithModel;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiAccountSetting;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\Project;
use App\Models\User;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoWorkReconciler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Image;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    config([
        'photostudio.disk' => 's3',
        'photostudio.provider' => 'auto',
        'photostudio.chooser.llm.enabled' => false,
        'photostudio.analysis.provider' => 'openrouter',
        'ai.providers.openrouter.key' => 'hosted-test-key',
        'ai.providers.replicate.key' => 'hosted-test-key',
        'ai.providers.stability.key' => 'hosted-test-key',
    ]);
});

test('batch analysis and generation retain the origin account after the actor switches accounts', function () {
    Queue::fake();
    Image::fake();
    PhotoBatchAnalyst::fake([[
        'subject' => 'Countertop',
        'intended_final_state' => 'Polished countertop',
        'style_notes' => 'Natural light',
        'group_prompt' => 'Create a polished countertop photograph.',
        'images' => [['index' => 0, 'verdict' => 'cleanup', 'defects' => [], 'prompt' => 'Polish it', 'text' => 'Countertop']],
    ]]);

    $actor = User::factory()->create();
    $originAccount = $actor->currentAccount()->firstOrFail();
    $otherAccount = Account::factory()->create();
    AccountEntitlement::factory()->for($otherAccount)->create();
    $otherAccount->members()->attach($actor, ['role' => AccountRole::Member->value]);
    AiAccountSetting::query()->create([
        'account_id' => $otherAccount->id,
        'user_id' => $actor->id,
        'paid_ai_enabled' => true,
        'hosted_ai_enabled' => true,
        'byok_enabled' => true,
        'max_concurrency' => 1,
    ]);
    $otherCredential = AiProviderCredential::query()->create([
        'account_id' => $otherAccount->id,
        'user_id' => $actor->id,
        'provider' => 'openrouter',
        'secret' => 'other-account-private-key',
        'fingerprint' => str_repeat('b', 64),
        'last_four' => 'leak',
    ]);
    $project = Project::factory()->for($actor, 'owner')->create(['account_id' => $originAccount->id]);
    $input = Photo::factory()->for($project)->for($actor)->create();
    Storage::disk('s3')->put($input->path, 'input-image');
    $batch = PhotoGenerationBatch::factory()->for($project)->for($actor)->create([
        'input_photo_ids' => [$input->id],
    ]);
    $serializedBatchJob = serialize(new RunPhotoGenerationBatch($batch));

    $actor->forceFill(['current_account_id' => $otherAccount->id])->save();

    /** @var RunPhotoGenerationBatch $batchJob */
    $batchJob = unserialize($serializedBatchJob);
    $batchJob->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));
    $slot = $batch->generationSlots()->firstOrFail();
    (new GeneratePhotoWithModel($slot->id))->handle(
        app(ImageModelCatalog::class),
        app(PhotoGenerationLifecycle::class),
    );

    $audits = AiOperationAudit::query()->where('actor_user_id', $actor->id)->get();

    expect($batch->fresh()->account_id)->toBe($originAccount->id)
        ->and($batch->fresh()->status)->toBe(GenerationBatchStatus::Processing)
        ->and($batch->generatedPhotos()->sole()->account_id)->toBe($originAccount->id)
        ->and($audits)->not->toBeEmpty()
        ->and($audits->pluck('account_id')->unique()->all())->toBe([$originAccount->id])
        ->and($audits->pluck('credential_fingerprint'))->not->toContain($otherCredential->fingerprint)
        ->and(AiOperationAudit::query()->where('account_id', $otherAccount->id)->exists())->toBeFalse()
        ->and($serializedBatchJob)->not->toContain('other-account-private-key')
        ->and(fn () => $batch->update(['account_id' => $otherAccount->id]))
        ->toThrow(LogicException::class, 'snapshot is immutable');
});

test('removing origin account membership denies a queued description for a remaining project guest', function () {
    PhotoDescriber::fake([['description' => 'This response must not be used.']]);

    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $originAccount = $owner->currentAccount()->firstOrFail();
    $originAccount->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $originAccount->id]);
    $project->sharedUsers()->attach($actor, ['permission' => 'write']);
    $photo = Photo::factory()->uncaptioned()->for($project)->for($actor)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
    ]);
    Storage::disk('s3')->put($photo->path, 'input-image');
    $job = new DescribeUploadedPhoto($photo);

    $originAccount->members()->detach($actor);
    $job->handle(app(PhotoGenerationLifecycle::class));

    PhotoDescriber::assertNeverPrompted();
    expect($photo->fresh()->text)->toBeNull()
        ->and($photo->fresh()->description_state)->toBe('failed')
        ->and($photo->fresh()->description_failure_code)->toBe('pre_provider_failure')
        ->and(AiOperationAudit::query()
            ->where('account_id', $originAccount->id)
            ->where('actor_user_id', $actor->id)
            ->where('event', AiAuditEvent::Prevented)
            ->exists())->toBeTrue();
});

test('description lease acquisition denial is failed and audited without starting AI', function () {
    PhotoDescriber::fake([['description' => 'Never used.']]);

    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $photo = Photo::factory()->uncaptioned()->for($project)->for($actor)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
    ]);
    $account->members()->detach($actor);

    (new DescribeUploadedPhoto($photo))->handle(app(PhotoGenerationLifecycle::class));

    PhotoDescriber::assertNeverPrompted();
    expect($photo->fresh()->description_state)->toBe('failed')
        ->and($photo->fresh()->description_failure_code)->toBe('pre_provider_failure')
        ->and($photo->fresh()->description_provider_started_at)->toBeNull()
        ->and(AiOperationAudit::query()->where('account_id', $account->id)->where('actor_user_id', $actor->id)->pluck('event')->all())
        ->toBe([AiAuditEvent::Prevented]);
});

test('description lease invalidation after claim is failed and audited without starting AI', function () {
    PhotoDescriber::fake([['description' => 'Never used.']]);

    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $photo = Photo::factory()->uncaptioned()->for($project)->for($actor)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
    ]);
    $revoked = false;
    Photo::updated(function (Photo $updated) use ($photo, $account, $actor, &$revoked): void {
        if (! $revoked && $updated->is($photo) && $updated->description_state === 'claimed') {
            $revoked = true;
            $account->members()->detach($actor);
        }
    });

    (new DescribeUploadedPhoto($photo))->handle(app(PhotoGenerationLifecycle::class));

    PhotoDescriber::assertNeverPrompted();
    expect($photo->fresh()->description_state)->toBe('failed')
        ->and($photo->fresh()->description_provider_started_at)->toBeNull()
        ->and(AiOperationAudit::query()->where('account_id', $account->id)->where('actor_user_id', $actor->id)->pluck('event')->all())
        ->toBe([AiAuditEvent::Prevented]);
});

test('batch lease acquisition denial is failed and audited without starting AI', function () {
    PhotoBatchAnalyst::fake([['group_prompt' => 'Never used.', 'images' => []]]);

    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $input = Photo::factory()->for($project)->for($actor)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($actor)->create(['input_photo_ids' => [$input->id]]);
    $account->members()->detach($actor);

    (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

    PhotoBatchAnalyst::assertNeverPrompted();
    expect($batch->fresh()->analysis_state)->toBe('failed')
        ->and($batch->fresh()->analysis_failure_code)->toBe('pre_provider_failure')
        ->and($batch->fresh()->analysis_provider_started_at)->toBeNull()
        ->and(AiOperationAudit::query()->where('account_id', $account->id)->where('actor_user_id', $actor->id)->pluck('event')->all())
        ->toBe([AiAuditEvent::Prevented]);
});

test('batch lease invalidation after claim is failed and audited without starting AI', function () {
    PhotoBatchAnalyst::fake([['group_prompt' => 'Never used.', 'images' => []]]);

    $owner = User::factory()->create();
    $actor = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $input = Photo::factory()->for($project)->for($actor)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($actor)->create(['input_photo_ids' => [$input->id]]);
    $revoked = false;
    PhotoGenerationBatch::updated(function (PhotoGenerationBatch $updated) use ($batch, $account, $actor, &$revoked): void {
        if (! $revoked && $updated->is($batch) && $updated->analysis_state === 'claimed') {
            $revoked = true;
            $account->members()->detach($actor);
        }
    });

    (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

    PhotoBatchAnalyst::assertNeverPrompted();
    expect($batch->fresh()->analysis_state)->toBe('failed')
        ->and($batch->fresh()->analysis_provider_started_at)->toBeNull()
        ->and(AiOperationAudit::query()->where('account_id', $account->id)->where('actor_user_id', $actor->id)->pluck('event')->all())
        ->toBe([AiAuditEvent::Prevented]);
});

test('workspace erasure fence rejects queued description claims before provider start', function () {
    PhotoDescriber::fake([['description' => 'Never used.']]);

    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $photo = Photo::factory()->uncaptioned()->for($project)->for($owner)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
    ]);
    $account->forceFill(['erasure_started_at' => now()])->save();

    (new DescribeUploadedPhoto($photo))->handle(app(PhotoGenerationLifecycle::class));

    PhotoDescriber::assertNeverPrompted();
    expect($photo->fresh()->description_state)->toBe('failed')
        ->and($photo->fresh()->description_failure_code)->toBe('workspace_erasure_started')
        ->and($photo->fresh()->description_provider_started_at)->toBeNull();
});

test('workspace erasure fence wins over invalid queued batch inputs without provider start', function () {
    PhotoBatchAnalyst::fake([['group_prompt' => 'Never used.', 'images' => []]]);

    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [PHP_INT_MAX],
    ]);
    $account->forceFill(['erasure_started_at' => now()])->save();

    (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

    PhotoBatchAnalyst::assertNeverPrompted();
    expect($batch->fresh()->analysis_state)->toBe('failed')
        ->and($batch->fresh()->analysis_failure_code)->toBe('workspace_erasure_started')
        ->and($batch->fresh()->analysis_provider_started_at)->toBeNull();
});

test('a project write guest cannot use workspace paid AI for queued batch analysis', function () {
    PhotoBatchAnalyst::fake([[
        'group_prompt' => 'This response must not be used.',
        'images' => [['index' => 0, 'verdict' => 'cleanup']],
    ]]);

    $owner = User::factory()->create();
    $guest = User::factory()->create();
    $originAccount = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $originAccount->id]);
    $project->sharedUsers()->attach($guest, ['permission' => 'write']);
    $input = Photo::factory()->for($project)->for($guest)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($guest)->create([
        'input_photo_ids' => [$input->id],
    ]);

    (new RunPhotoGenerationBatch($batch))->handle(
        app(ImageModelChooser::class),
        app(PhotoGenerationLifecycle::class),
    );

    PhotoBatchAnalyst::assertNeverPrompted();
    expect($batch->fresh()->analysis_state)->toBe('failed')
        ->and($batch->fresh()->analysis_failure_code)->toBe('pre_provider_failure')
        ->and($batch->fresh()->generationSlots()->exists())->toBeFalse()
        ->and(AiOperationAudit::query()
            ->where('account_id', $originAccount->id)
            ->where('actor_user_id', $guest->id)
            ->where('event', AiAuditEvent::Prevented)
            ->exists())->toBeTrue();
});

test('budget and entitlement denials fail queued descriptions before provider start', function (string $denial) {
    PhotoDescriber::fake([['description' => 'This response must not be used.']]);

    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $photo = Photo::factory()->uncaptioned()->for($project)->for($owner)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
    ]);
    Storage::disk('s3')->put($photo->path, 'input-image');

    if ($denial === 'budget') {
        AiAccountSetting::factory()->for($owner)->create(['per_operation_usd_limit' => 0]);
    } elseif ($denial === 'entitlement') {
        $account->entitlement()->sole()->update(['status' => AccountEntitlementStatus::Suspended]);
    } else {
        AiAccountSetting::factory()->for($owner)->create(['max_concurrency' => 1]);
        AiOperationAudit::query()->create([
            'operation_id' => fake()->uuid(),
            'account_id' => $account->id,
            'actor_user_id' => $owner->id,
            'event' => AiAuditEvent::Started,
            'purpose' => AiModelPurpose::ShortText,
            'provider' => 'openrouter',
            'model' => 'openrouter/auto',
            'occurred_at' => now(),
        ]);
    }

    (new DescribeUploadedPhoto($photo))->handle(app(PhotoGenerationLifecycle::class));

    PhotoDescriber::assertNeverPrompted();
    expect($photo->fresh()->description_state)->toBe('failed')
        ->and($photo->fresh()->description_failure_code)->toBe('pre_provider_failure')
        ->and($photo->fresh()->description_provider_started_at)->toBeNull()
        ->and(AiOperationAudit::query()
            ->where('account_id', $account->id)
            ->where('actor_user_id', $owner->id)
            ->where('event', AiAuditEvent::Prevented)
            ->exists())->toBeTrue();
})->with(['budget', 'entitlement', 'concurrency']);

test('reconciliation and job serialization keep account identity in durable rows rather than payload fields', function () {
    Queue::fake();

    $actor = User::factory()->create();
    $originAccount = $actor->currentAccount()->firstOrFail();
    $otherAccount = Account::factory()->create();
    AccountEntitlement::factory()->for($otherAccount)->create();
    $otherAccount->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $project = Project::factory()->for($actor, 'owner')->create(['account_id' => $originAccount->id]);
    $photo = Photo::factory()->uncaptioned()->for($project)->for($actor)->create([
        'processing_status' => PhotoProcessingStatus::Ready,
        'description_enqueued_at' => null,
    ]);
    $batch = PhotoGenerationBatch::factory()->for($project)->for($actor)->create([
        'analysis_enqueued_at' => null,
        'analysis_state' => 'pending',
    ]);
    $slot = PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create([
        'status' => PhotoGenerationSlotStatus::Pending,
        'enqueued_at' => null,
        'mode' => PhotoMode::Cleanup,
    ]);

    $actor->forceFill(['current_account_id' => $otherAccount->id])->save();
    $counts = app(PhotoWorkReconciler::class)->reconcile(10);

    expect($counts['descriptions'])->toBe(1)
        ->and($counts['batches'])->toBe(1)
        ->and($counts['slots'])->toBe(1)
        ->and($photo->fresh()->account_id)->toBe($originAccount->id)
        ->and($batch->fresh()->account_id)->toBe($originAccount->id)
        ->and(serialize(new DescribeUploadedPhoto($photo)))->not->toContain('accountId')
        ->and(serialize(new RunPhotoGenerationBatch($batch)))->not->toContain('accountId')
        ->and(serialize(new GeneratePhotoWithModel($slot->id)))->not->toContain('accountId');
    Queue::assertPushed(DescribeUploadedPhoto::class, fn (DescribeUploadedPhoto $job): bool => $job->photo->account_id === $originAccount->id);
    Queue::assertPushed(RunPhotoGenerationBatch::class, fn (RunPhotoGenerationBatch $job): bool => $job->generationBatch->account_id === $originAccount->id);
    Queue::assertPushed(GeneratePhotoWithModel::class, fn (GeneratePhotoWithModel $job): bool => $job->slotId === $slot->id);
});

test('photo work migrations backfill from projects and reject a mismatched account snapshot', function () {
    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'photo_work_account_scope';

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

    try {
        Schema::create('accounts', fn (Blueprint $table) => $table->id());
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
        });
        Schema::create('photo_generation_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
        });
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('photo_generation_batch_id')->nullable();
        });
        Schema::create('photo_operation_leases', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('project_id');
        });
        DB::table('accounts')->insert([['id' => 10], ['id' => 20]]);
        DB::table('projects')->insert(['id' => 1, 'account_id' => 10]);
        DB::table('photo_generation_batches')->insert(['id' => 2, 'project_id' => 1]);
        DB::table('photos')->insert(['id' => 3, 'project_id' => 1, 'photo_generation_batch_id' => 2]);
        DB::table('photo_operation_leases')->insert(['id' => 'lease-4', 'project_id' => 1]);

        (require database_path('migrations/2026_07_15_115805_add_account_scope_to_photo_work.php'))->up();
        (require database_path('migrations/2026_07_15_115806_backfill_account_scope_to_photo_work.php'))->up();

        expect(DB::table('photos')->value('account_id'))->toBe(10)
            ->and(DB::table('photo_generation_batches')->value('account_id'))->toBe(10)
            ->and(DB::table('photo_operation_leases')->value('account_id'))->toBe(10);

        DB::table('photos')->where('id', 3)->update(['account_id' => 20]);
        $enforcement = require database_path('migrations/2026_07_15_115808_enforce_account_scope_on_photo_work.php');

        expect(fn () => $enforcement->up())
            ->toThrow(RuntimeException::class, 'photos without a valid project account snapshot [3]');
    } finally {
        DB::disconnect($isolatedConnection);
        DB::purge($isolatedConnection);
        DB::setDefaultConnection($originalConnection);
    }
});
