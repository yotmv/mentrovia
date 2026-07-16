<?php

use App\Actions\Accounts\StartWorkspaceErasure;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Enums\PhotoKind;
use App\Enums\PhotoProcessingStatus;
use App\Exceptions\WorkspaceErasureFailed;
use App\Jobs\EraseWorkspaceData;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\PhotoStorageCleanup;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkspaceErasureObject;
use App\Models\WorkspaceErasureProgress;
use App\Services\Accounts\CurrentAccount;
use App\Services\WorkspaceErasureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('workspace erasure progress is separate durable account state', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->forceFill(['erasure_started_at' => now()])->save();

    $progress = WorkspaceErasureProgress::query()->create([
        'account_id' => $account->id,
        'requested_by_user_id' => $owner->id,
    ]);

    expect($account->fresh()?->erasure_started_at)->not->toBeNull()
        ->and($progress->phase)->toBe('drain_work')
        ->and($progress->cursor)->toBe(0)
        ->and($progress->revision)->toBe(0)
        ->and($progress->completed_at)->toBeNull();
});

test('storage proof prevents cleanup pruning until its manifest is removed', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $progress = WorkspaceErasureProgress::factory()->create([
        'account_id' => $account->id,
        'requested_by_user_id' => $owner->id,
    ]);
    $path = 'generated_/staging/proof/original.jpg';
    $cleanup = PhotoStorageCleanup::factory()->create([
        'path' => $path,
        'path_hash' => hash('sha256', $path),
        'completed_at' => now()->subDays(31),
    ]);
    $manifest = WorkspaceErasureObject::factory()->create([
        'workspace_erasure_progress_id' => $progress->id,
        'photo_storage_cleanup_id' => $cleanup->id,
        'path' => $path,
        'path_hash' => hash('sha256', $path),
    ]);

    expect($cleanup->prunable()->whereKey($cleanup)->exists())->toBeFalse();

    $manifest->delete();

    expect($cleanup->prunable()->whereKey($cleanup)->exists())->toBeTrue();
});

test('only the owner with exact name and current password can fence a workspace', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $this->actingAs($owner);

    expect(fn () => app(StartWorkspaceErasure::class)->handle($account, $owner, 'wrong', 'password'))
        ->toThrow(ValidationException::class);

    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');

    expect($account->fresh()?->erasure_started_at)->not->toBeNull()
        ->and($progress->requested_by_user_id)->toBe($owner->id)
        ->and($progress->dispatch_token)->not->toBeNull()
        ->and(DB::table('account_user')->where('account_id', $account->id)->exists())->toBeFalse()
        ->and($owner->fresh()?->current_account_id)->not->toBe($account->id)
        ->and($owner->fresh())->not->toBeNull();
});

test('workspace start evacuates every member and revokes project guest access', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $guest = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $memberOriginalAccountId = $member->current_account_id;
    $account->members()->attach($member, ['role' => AccountRole::Member->value]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $project->sharedUsers()->attach($guest, ['permission' => 'write']);
    $this->actingAs($owner);

    app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');

    expect($member->fresh()?->current_account_id)->toBe($memberOriginalAccountId)
        ->and(DB::table('project_user')->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(DB::table('account_user')->where('account_id', $account->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey([$owner->id, $member->id, $guest->id])->count())->toBe(3);
});

test('workspace erasure verifies represented and orphaned storage before preserving audits and users', function () {
    Storage::fake('s3');
    Queue::fake();
    config([
        'photostudio.workspace_erasure_chunk_size' => 50,
        'photostudio.workspace_erasure_chunks_per_job' => 50,
    ]);
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $sourcePath = 'uploaded_'.Str::uuid7().'/original.jpg';
    $derivativePath = dirname($sourcePath).'/derivatives/attempt/card.webp';
    $orphanDerivativePath = dirname($sourcePath).'/derivatives/lost/thumb.webp';
    $photo = Photo::factory()->for($project)->for($owner)->create([
        'path' => $sourcePath,
        'derivatives' => ['card' => ['path' => $derivativePath]],
    ]);
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create();
    $stagingPrefix = 'generated_/staging/'.Str::uuid7().'/';
    $stagedPath = $stagingPrefix.'original.png';
    $orphanStagedPath = $stagingPrefix.'lost-provider-output.png';
    PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create([
        'staged_disk' => 's3',
        'staged_path' => $stagedPath,
        'staging_prefix' => $stagingPrefix,
    ]);

    foreach ([$sourcePath, $derivativePath, $orphanDerivativePath, $stagedPath, $orphanStagedPath] as $path) {
        Storage::disk('s3')->put($path, 'private');
    }

    $operationId = (string) Str::uuid();
    $audit = AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Started,
        'occurred_at' => now(),
    ]);
    AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Succeeded,
        'occurred_at' => now(),
    ]);
    $ownerId = $owner->id;
    $accountId = $account->id;
    $this->actingAs($owner);
    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');

    (new EraseWorkspaceData($accountId, (string) $progress->dispatch_token))
        ->handle(app(WorkspaceErasureService::class));

    $completed = WorkspaceErasureProgress::query()->where('account_id', $accountId)->sole();

    expect($completed->phase)->toBe('completed')
        ->and($completed->storage_verified_at)->not->toBeNull()
        ->and($completed->completed_at)->not->toBeNull()
        ->and($account->fresh())->toBeNull()
        ->and(Photo::query()->whereKey($photo->id)->exists())->toBeFalse()
        ->and(User::query()->find($ownerId))->not->toBeNull()
        ->and(AiOperationAudit::query()->find($audit->id)?->account_id)->toBe($accountId);

    foreach ([$sourcePath, $derivativePath, $orphanDerivativePath, $stagedPath, $orphanStagedPath] as $path) {
        Storage::disk('s3')->assertMissing($path);
    }
});

test('workspace erasure adopts a provisional upload after a process crash between storage and finalization', function () {
    Storage::fake('s3');
    Queue::fake();
    config([
        'photostudio.workspace_erasure_chunk_size' => 50,
        'photostudio.workspace_erasure_chunks_per_job' => 50,
    ]);
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $path = 'uploaded_'.Str::uuid7().'/original.jpg';
    $photo = Photo::factory()->for($project)->for($owner)->create([
        'kind' => PhotoKind::Uploaded,
        'disk' => 's3',
        'path' => $path,
        'processing_status' => PhotoProcessingStatus::Pending,
        'processing_error' => 'upload_in_progress',
        'derivatives_enqueued_at' => now(),
    ]);
    Storage::disk('s3')->put($path, 'process-crashed-after-this-write');
    $accountId = $account->id;
    $this->actingAs($owner);

    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');
    (new EraseWorkspaceData($accountId, (string) $progress->dispatch_token))
        ->handle(app(WorkspaceErasureService::class));

    Storage::disk('s3')->assertMissing($path);
    expect(Photo::query()->whereKey($photo->id)->exists())->toBeFalse()
        ->and(Account::query()->whereKey($accountId)->exists())->toBeFalse();
});

test('a missing account cannot produce a completed tombstone without storage proof', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $accountId = $account->id;
    $token = (string) Str::uuid7();
    $account->forceFill(['erasure_started_at' => now()])->save();
    $progress = WorkspaceErasureProgress::query()->create([
        'account_id' => $accountId,
        'requested_by_user_id' => $owner->id,
        'phase' => 'delete_account',
        'dispatch_token' => $token,
    ]);
    $owner->forceFill(['current_account_id' => null])->save();
    DB::table('account_user')->where('account_id', $accountId)->delete();
    $account->delete();

    expect(fn () => app(WorkspaceErasureService::class)->resume($accountId, $token))
        ->toThrow(WorkspaceErasureFailed::class)
        ->and($progress->fresh()?->phase)->toBe('delete_account')
        ->and($progress->fresh()?->completed_at)->toBeNull();
});

test('cached current account resolution revalidates the workspace erasure fence', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $resolver = app(CurrentAccount::class);

    expect($resolver->resolve($owner)->id)->toBe($account->id);

    Account::query()->whereKey($account->id)->update(['erasure_started_at' => now()]);

    expect(fn () => $resolver->resolve($owner))->toThrow(AuthorizationException::class);
});

test('workspace erasure drains an admitted paid AI operation before storage scanning', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $operationId = (string) Str::uuid7();
    AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Started,
        'occurred_at' => now(),
    ]);
    $this->actingAs($owner);
    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');
    $token = (string) $progress->dispatch_token;

    expect(app(WorkspaceErasureService::class)->resume($account->id, $token))->toBeFalse()
        ->and($progress->fresh()?->phase)->toBe('drain_work');

    AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Succeeded,
        'occurred_at' => now(),
    ]);

    expect(app(WorkspaceErasureService::class)->resume($account->id, $token))->toBeFalse()
        ->and($progress->fresh()?->phase)->toBe('scan_photos');
});
