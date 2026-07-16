<?php

use App\Actions\Users\EraseUserAccount;
use App\Exceptions\AccountErasureFailed;
use App\Jobs\EraseUserAccountData;
use App\Models\AccountErasureProgress;
use App\Models\Business;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use App\Services\PhotoGenerationLifecycle;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    Queue::fake();
    config(['photostudio.disk' => 's3']);
});

test('an account pending erasure cannot log in', function () {
    $user = User::factory()->create([
        'password' => 'password',
        'account_erasure_started_at' => now(),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('an existing session is invalidated once account erasure starts', function () {
    $user = User::factory()->create(['account_erasure_started_at' => now()]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('home'));

    $this->assertGuest();
});

test('account erasure waits for an active lease and completes after it finishes', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $lifecycle = app(PhotoGenerationLifecycle::class);
    $lease = $lifecycle->acquireForProject($project, $user, 'test-provider-operation');

    expect($lease)->not->toBeNull()
        ->and($lifecycle->hasActiveLeases($user))->toBeTrue();

    $this->actingAs($user);
    app(EraseUserAccount::class)->handle($user);

    expect(app(EraseUserAccount::class)->resume($user->id))->toBeFalse()
        ->and($user->fresh())->not->toBeNull();

    $lifecycle->finish($lease);

    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($user->fresh())->toBeNull();
});

test('an expired operation lease no longer delays account erasure', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $lifecycle = app(PhotoGenerationLifecycle::class);
    $lease = $lifecycle->acquireForProject($project, $user, 'abandoned-operation');

    $this->travel((int) config('photostudio.operation_lease_seconds') + 1)->seconds();

    expect($lifecycle->leaseIsUsable($lease))->toBeFalse()
        ->and($lifecycle->hasActiveLeases($user))->toBeFalse();

    $this->actingAs($user);
    app(EraseUserAccount::class)->handle($user);

    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($user->fresh())->toBeNull();
});

test('a durable queue write failure rolls back the erasure marker', function () {
    Queue::fake()->except(EraseUserAccountData::class);
    config(['queue.connections.lifecycle-database.table' => 'missing_jobs_table']);

    $user = User::factory()->create();
    Livewire::actingAs($user)
        ->test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['accountDeletion'])
        ->assertSee('Your account remains active');

    expect($user->fresh()?->account_erasure_started_at)->toBeNull()
        ->and(auth()->id())->toBe($user->id);
});

test('an enqueue failure leaves no cache marker and a later attempt commits exactly one durable job', function () {
    Queue::fake()->except(EraseUserAccountData::class);
    config([
        'cache.default' => 'array',
        'queue.connections.lifecycle-database.table' => 'missing_jobs_table',
    ]);

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => app(EraseUserAccount::class)->handle($user))
        ->toThrow(AccountErasureFailed::class);

    expect($user->fresh()?->account_erasure_started_at)->toBeNull()
        ->and(AccountErasureProgress::query()->where('user_id', $user->id)->exists())->toBeFalse();

    Schema::create('missing_jobs_table', function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    app(EraseUserAccount::class)->handle($user);
    app(EraseUserAccount::class)->handle($user);

    expect($user->fresh()?->account_erasure_started_at)->not->toBeNull()
        ->and(AccountErasureProgress::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('missing_jobs_table')->count())->toBe(1);
});

test('large account erasure advances in durable bounded chunks before finalization', function () {
    config([
        'photostudio.account_erasure_chunk_size' => 2,
        'photostudio.account_erasure_chunks_per_job' => 1,
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $photos = Photo::factory()->count(5)->for($project)->for($user)->create();

    foreach ($photos as $photo) {
        Storage::disk('s3')->put($photo->path, 'private-photo');
    }

    $this->actingAs($user);
    app(EraseUserAccount::class)->handle($user);

    $progressId = AccountErasureProgress::query()->where('user_id', $user->id)->value('id');
    $observedPhotoCounts = [];
    $observedLinkedCleanup = false;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        (new EraseUserAccountData($user->id))->handle(app(EraseUserAccount::class));
        $observedPhotoCounts[] = Photo::query()->where('user_id', $user->id)->count();
        $observedLinkedCleanup = $observedLinkedCleanup || DB::table('account_erasure_cleanup')
            ->where('account_erasure_progress_id', $progressId)
            ->exists();

        if (AccountErasureProgress::query()->where('user_id', $user->id)->value('phase') === 'workspace_erasure') {
            break;
        }
    }

    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($observedPhotoCounts)->toContain(3, 1)
        ->and($observedLinkedCleanup)->toBeTrue()
        ->and($user->fresh())->toBeNull()
        ->and(Photo::query()->where('user_id', $user->id)->exists())->toBeFalse();

    foreach ($photos as $photo) {
        Storage::disk('s3')->assertMissing($photo->path);
    }
});

test('established company erasure needs multiple chunks and waits for linked object cleanup', function () {
    config([
        'photostudio.account_erasure_chunk_size' => 1,
        'photostudio.account_erasure_chunks_per_job' => 1,
    ]);

    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create(['stage' => 'existing_entity']);
    $project = Project::factory()->for($user, 'owner')->create();
    $photos = Photo::factory()->count(3)->for($project)->for($user)->create();
    $objectsExist = true;

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturnFalse();
    $disk->shouldReceive('exists')->andReturnUsing(function () use (&$objectsExist): bool {
        return $objectsExist;
    });

    $filesystems = Mockery::mock(FilesystemFactory::class);
    $filesystems->shouldReceive('disk')->with('s3')->andReturn($disk);
    $this->app->instance(FilesystemFactory::class, $filesystems);

    $this->actingAs($user);
    app(EraseUserAccount::class)->handle($user);

    $attempts = 0;

    while (AccountErasureProgress::query()->where('user_id', $user->id)->value('phase') !== 'storage_cleanup' && $attempts < 100) {
        (new EraseUserAccountData($user->id))->handle(app(EraseUserAccount::class));
        $attempts++;
    }

    $progress = AccountErasureProgress::query()->where('user_id', $user->id)->sole();

    expect($attempts)->toBeGreaterThan(3)
        ->and($progress->phase)->toBe('storage_cleanup')
        ->and($user->fresh())->not->toBeNull()
        ->and($business->fresh())->not->toBeNull()
        ->and(DB::table('account_erasure_cleanup')
            ->where('account_erasure_progress_id', $progress->id)
            ->exists())->toBeTrue();

    $objectsExist = false;

    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($user->fresh())->toBeNull()
        ->and($business->fresh())->toBeNull();
});
