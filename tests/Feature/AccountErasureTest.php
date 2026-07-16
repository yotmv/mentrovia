<?php

use App\Actions\Users\EraseUserAccount;
use App\Enums\AiAuditEvent;
use App\Jobs\EraseUserAccountData;
use App\Models\AdvertisingKit;
use App\Models\AgentConversation;
use App\Models\AiAccountSetting;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\BusinessProfile;
use App\Models\BusinessTask;
use App\Models\KnowledgeArticle;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserFeedback;
use App\Models\ValidationRun;
use App\Models\ValidationVote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\mock;

test('account erasure cannot target another user', function () {
    Storage::fake('s3');

    $actor = User::factory()->create();
    $target = User::factory()->create();
    $this->actingAs($actor);

    expect(fn () => app(EraseUserAccount::class)->handle($target))
        ->toThrow(AuthorizationException::class);

    expect($target->fresh())->not->toBeNull();
});

test('account deletion erases owned data and files while preserving unrelated records', function () {
    Storage::fake('s3');
    Queue::fake();

    $user = User::factory()->create();
    $personalAccount = $user->currentAccount;
    $personalEntitlement = $personalAccount->entitlement;
    $otherUser = User::factory()->create();
    $aiSetting = AiAccountSetting::factory()->for($user)->create(['byok_enabled' => true]);
    $aiCredential = AiProviderCredential::factory()->for($user)->create();
    $aiAudit = AiOperationAudit::query()->create([
        'operation_id' => fake()->uuid(),
        'account_id' => $user->id,
        'actor_user_id' => $user->id,
        'event' => AiAuditEvent::CredentialSaved,
        'credential_fingerprint' => $aiCredential->fingerprint,
        'occurred_at' => now(),
    ]);
    $business = Business::factory()->for($user)->create();
    $profile = BusinessProfile::factory()->for($business)->create();
    $task = BusinessTask::factory()->for($business)->create();
    $completion = TaskCompletion::factory()->for($task, 'task')->for($business)->create();
    $brandKit = BrandKit::factory()->forBusiness($business)->create();
    $advertisingKit = AdvertisingKit::factory()->forBusiness($business)->create();
    $feedback = UserFeedback::factory()->for($user)->create();

    $article = KnowledgeArticle::factory()->create();
    $validationRun = ValidationRun::factory()
        ->for($article, 'article')
        ->forBusiness($business)
        ->create();
    $validationVote = ValidationVote::factory()->for($validationRun)->create();

    $ownedProject = Project::factory()->for($user, 'owner')->create();
    $ownedProject->sharedUsers()->attach($otherUser->id, ['permission' => 'write']);
    $batch = PhotoGenerationBatch::factory()->for($ownedProject)->for($user)->create();
    $ownedPhoto = Photo::factory()
        ->withDerivatives()
        ->for($ownedProject)
        ->for($user)
        ->for($batch, 'generationBatch')
        ->create();
    $collaboratorPhoto = Photo::factory()
        ->withDerivatives()
        ->for($ownedProject)
        ->for($otherUser)
        ->create();

    $sharedProject = Project::factory()->for($otherUser, 'owner')->create();
    $sharedProject->sharedUsers()->attach($user->id, ['permission' => 'write']);
    $incomingInvitation = ProjectInvitation::factory()
        ->for($sharedProject)
        ->for($otherUser, 'inviter')
        ->create(['email' => $user->email]);
    $unrelatedInvitation = ProjectInvitation::factory()
        ->for($sharedProject)
        ->for($otherUser, 'inviter')
        ->create(['email' => 'keep@example.com']);
    $usersPhotoInSharedProject = Photo::factory()
        ->withDerivatives()
        ->for($sharedProject)
        ->for($user)
        ->create();
    $unrelatedPhoto = Photo::factory()
        ->withDerivatives()
        ->for($sharedProject)
        ->for($otherUser)
        ->create();

    $conversation = AgentConversation::create([
        'user_id' => $user->id,
        'title' => 'Private advisor conversation',
    ]);
    $message = $conversation->messages()->create([
        'user_id' => $user->id,
        'agent' => 'advisor',
        'role' => 'user',
        'content' => 'Private company details',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $otherConversation = AgentConversation::create([
        'user_id' => $otherUser->id,
        'title' => 'Unrelated advisor conversation',
    ]);
    $otherMessage = $otherConversation->messages()->create([
        'user_id' => $otherUser->id,
        'agent' => 'advisor',
        'role' => 'user',
        'content' => 'Keep this conversation',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'another-user-session',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'private-session-data',
        'last_activity' => now()->timestamp,
    ]);
    DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->insert([
        'email' => $user->email,
        'token' => 'private-reset-token',
        'created_at' => now(),
    ]);

    $photosToErase = collect([$ownedPhoto, $collaboratorPhoto, $usersPhotoInSharedProject]);

    foreach ($photosToErase->concat([$unrelatedPhoto]) as $photo) {
        Storage::disk($photo->disk)->put($photo->path, 'source');

        foreach ($photo->derivatives ?? [] as $derivative) {
            Storage::disk($photo->disk)->put($derivative['path'], 'derivative');
        }
    }

    $response = Livewire::actingAs($user)
        ->test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh()?->account_erasure_started_at)->not->toBeNull();
    Queue::assertPushed(EraseUserAccountData::class, 1);

    simulateWorkspaceErasureAndFinishUser($user->id);

    foreach ($photosToErase as $photo) {
        Storage::disk($photo->disk)->assertMissing($photo->path);

        foreach ($photo->derivatives ?? [] as $derivative) {
            Storage::disk($photo->disk)->assertMissing($derivative['path']);
        }
    }

    Storage::disk($unrelatedPhoto->disk)->assertExists($unrelatedPhoto->path);

    expect($user->fresh())->toBeNull()
        ->and($personalAccount->fresh())->toBeNull()
        ->and($personalEntitlement->fresh())->toBeNull()
        ->and($aiSetting->fresh())->toBeNull()
        ->and($aiCredential->fresh())->toBeNull()
        ->and($aiAudit->fresh())->not->toBeNull()
        ->and($business->fresh())->toBeNull()
        ->and($profile->fresh())->toBeNull()
        ->and($task->fresh())->toBeNull()
        ->and($completion->fresh())->toBeNull()
        ->and($brandKit->fresh())->toBeNull()
        ->and($advertisingKit->fresh())->toBeNull()
        ->and($feedback->fresh())->toBeNull()
        ->and($validationRun->fresh())->toBeNull()
        ->and($validationVote->fresh())->toBeNull()
        ->and($ownedProject->fresh())->toBeNull()
        ->and($batch->fresh())->toBeNull()
        ->and($ownedPhoto->fresh())->toBeNull()
        ->and($collaboratorPhoto->fresh())->toBeNull()
        ->and($usersPhotoInSharedProject->fresh())->toBeNull()
        ->and($conversation->fresh())->toBeNull()
        ->and($message->fresh())->toBeNull()
        ->and(auth()->check())->toBeFalse()
        ->and($otherUser->fresh())->not->toBeNull()
        ->and($sharedProject->fresh())->not->toBeNull()
        ->and($incomingInvitation->fresh())->toBeNull()
        ->and($unrelatedInvitation->fresh())->not->toBeNull()
        ->and($unrelatedPhoto->fresh())->not->toBeNull()
        ->and($otherConversation->fresh())->not->toBeNull()
        ->and($otherMessage->fresh())->not->toBeNull()
        ->and(DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))->where('email', $user->email)->exists())->toBeFalse();
});

test('account deletion erases accepted invitations after the recipient changes email', function () {
    Storage::fake('s3');
    Queue::fake();

    $user = User::factory()->create(['email' => 'original@example.com']);
    $otherUser = User::factory()->create();
    $project = Project::factory()->for($otherUser, 'owner')->create();
    $acceptedInvitation = ProjectInvitation::factory()
        ->for($project)
        ->for($otherUser, 'inviter')
        ->for($user, 'acceptedBy')
        ->create([
            'email' => 'original@example.com',
            'accepted_at' => now(),
        ]);
    $unrelatedInvitation = ProjectInvitation::factory()
        ->for($project)
        ->for($otherUser, 'inviter')
        ->create(['email' => 'keep@example.com']);

    $user->update(['email' => 'changed@example.com']);
    $this->actingAs($user);

    app(EraseUserAccount::class)->handle($user);
    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($acceptedInvitation->fresh())->toBeNull()
        ->and($unrelatedInvitation->fresh())->not->toBeNull();
});

test('account deletion erases batches and generated output derived from the users input photo', function () {
    Storage::fake('s3');
    Queue::fake();

    $owner = User::factory()->create();
    $user = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->sharedUsers()->attach($user, ['permission' => 'write']);
    $input = Photo::factory()->for($project)->for($user)->create();
    $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
        'input_photo_ids' => [$input->id],
    ]);
    $generated = Photo::factory()->generated()->for($project)->for($owner)->create([
        'photo_generation_batch_id' => $batch->id,
    ]);

    foreach ([$input, $generated] as $photo) {
        Storage::disk('s3')->put($photo->path, 'private-derived-data');
    }

    $this->actingAs($user);
    app(EraseUserAccount::class)->handle($user);
    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($user->fresh())->toBeNull()
        ->and($input->fresh())->toBeNull()
        ->and($batch->fresh())->toBeNull()
        ->and($generated->fresh())->toBeNull()
        ->and($owner->fresh())->not->toBeNull()
        ->and($project->fresh())->not->toBeNull();
    Storage::disk('s3')->assertMissing($input->path);
    Storage::disk('s3')->assertMissing($generated->path);
});

test('account deletion remains locked and retries idempotently when stored files cannot be erased', function () {
    Storage::fake('s3');
    Queue::fake();

    $user = User::factory()->create();
    $project = Project::factory()->for($user, 'owner')->create();
    $photo = Photo::factory()->withDerivatives()->for($project)->for($user)->create();

    Storage::disk('s3')->put($photo->path, 'source');

    $objectExists = true;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andReturnFalse();
    $disk->shouldReceive('exists')->andReturnUsing(
        function (string $path) use (&$objectExists, $photo): bool {
            return $path === $photo->path && $objectExists;
        },
    );

    mock(FilesystemFactory::class)
        ->shouldReceive('disk')
        ->with('s3')
        ->andReturn($disk);

    Livewire::actingAs($user)
        ->test('settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    (new EraseUserAccountData($user->id))->handle(app(EraseUserAccount::class));

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()?->account_erasure_started_at)->not->toBeNull()
        ->and($project->fresh())->not->toBeNull()
        ->and($photo->fresh())->toBeNull()
        ->and(auth()->check())->toBeFalse();

    $objectExists = false;
    simulateWorkspaceErasureAndFinishUser($user->id);

    expect($user->fresh())->toBeNull()
        ->and($project->fresh())->toBeNull()
        ->and($photo->fresh())->toBeNull();
});
