<?php

use App\Actions\Accounts\AcceptAccountInvitation;
use App\Actions\Projects\AcceptProjectInvitation;
use App\Actions\Users\EraseUserAccount;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Exceptions\AccountErasureFailed;
use App\Jobs\EraseUserAccountData;
use App\Jobs\EraseWorkspaceData;
use App\Models\AccountInvitation;
use App\Models\AdvertisingKit;
use App\Models\AgentConversation;
use App\Models\AiOperationAudit;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Models\ValidationRun;
use App\Models\WorkspaceErasureProgress;
use App\Services\Advisor\AdvisorAnswerService;
use App\Services\WorkspaceErasureService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('s3');
    Queue::fake();
});

test('departing company members lose private and contributed data without deleting company records', function () {
    $companyOwner = User::factory()->create();
    $departingUser = User::factory()->create();
    $personalAccount = $departingUser->currentAccount;
    $companyAccount = $companyOwner->currentAccount;

    DB::table('account_user')->insert([
        'account_id' => $companyAccount->id,
        'user_id' => $departingUser->id,
        'role' => AccountRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $business = Business::factory()->for($departingUser)->create(['account_id' => $companyAccount->id]);
    $project = Project::factory()->for($departingUser, 'owner')->create(['account_id' => $companyAccount->id]);
    $brandKit = BrandKit::factory()->forBusiness($business)->create();
    $advertisingKit = AdvertisingKit::factory()->forBusiness($business)->create();
    $invitation = ProjectInvitation::factory()
        ->for($project)
        ->for($departingUser, 'inviter')
        ->create(['email' => 'future-member@example.com']);
    $outgoingAccountInvitation = AccountInvitation::factory()
        ->for($companyAccount)
        ->for($departingUser, 'inviter')
        ->create(['email' => 'future-account-member@example.com']);
    $incomingAccountInvitation = AccountInvitation::factory()
        ->for($companyAccount)
        ->for($companyOwner, 'inviter')
        ->create(['email' => $departingUser->email]);
    $validationRun = ValidationRun::factory()
        ->for(KnowledgeArticle::factory(), 'article')
        ->forBusiness($business)
        ->create();
    $audit = AiOperationAudit::query()->create([
        'operation_id' => (string) Str::uuid(),
        'account_id' => $companyAccount->id,
        'actor_user_id' => $departingUser->id,
        'event' => AiAuditEvent::Started,
        'occurred_at' => now(),
    ]);

    $contributedPhoto = Photo::factory()->for($project)->for($departingUser)->create();
    $survivingPhoto = Photo::factory()->for($project)->for($companyOwner)->create();
    $initiatedBatch = PhotoGenerationBatch::factory()->for($project)->for($departingUser)->create();
    $initiatedOutput = Photo::factory()->for($project)->for($companyOwner)->create([
        'photo_generation_batch_id' => $initiatedBatch->id,
    ]);
    $derivedBatch = PhotoGenerationBatch::factory()->for($project)->for($companyOwner)->create([
        'input_photo_ids' => [$contributedPhoto->id],
    ]);
    $derivedOutput = Photo::factory()->for($project)->for($companyOwner)->create([
        'photo_generation_batch_id' => $derivedBatch->id,
    ]);

    foreach ([$contributedPhoto, $survivingPhoto, $initiatedOutput, $derivedOutput] as $photo) {
        Storage::disk('s3')->put($photo->path, 'photo-data');
    }

    $authoredConversation = AgentConversation::query()->create([
        'account_id' => $companyAccount->id,
        'user_id' => $departingUser->id,
        'title' => 'Authored private conversation',
    ]);
    $authoredMessage = $authoredConversation->messages()->create([
        'user_id' => $departingUser->id,
        'agent' => 'advisor',
        'role' => 'user',
        'content' => 'Departing user private context',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);
    $participatedConversation = AgentConversation::query()->create([
        'account_id' => $companyAccount->id,
        'user_id' => $companyOwner->id,
        'title' => 'Shared private conversation',
    ]);
    $participatedMessage = $participatedConversation->messages()->create([
        'user_id' => $departingUser->id,
        'agent' => 'advisor',
        'role' => 'user',
        'content' => 'Departing participant private context',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);
    $survivingConversation = AgentConversation::query()->create([
        'account_id' => $companyAccount->id,
        'user_id' => $companyOwner->id,
        'title' => 'Owner-only conversation',
    ]);
    $survivingMessage = $survivingConversation->messages()->create([
        'user_id' => $companyOwner->id,
        'agent' => 'advisor',
        'role' => 'user',
        'content' => 'Company owner private context',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);

    $this->actingAs($departingUser);
    app(EraseUserAccount::class)->handle($departingUser);

    expect(DB::table('account_erasure_targets')
        ->where('user_id', $departingUser->id)
        ->where('resource_type', 'account')
        ->pluck('resource_id')->all())->toBe([$personalAccount->id]);

    simulateWorkspaceErasureAndFinishUser($departingUser->id);

    expect($departingUser->fresh())->toBeNull()
        ->and($personalAccount->fresh())->toBeNull()
        ->and($companyAccount->fresh())->not->toBeNull()
        ->and($business->fresh())->not->toBeNull()
        ->and($business->fresh()?->user_id)->toBeNull()
        ->and($project->fresh())->not->toBeNull()
        ->and($project->fresh()?->user_id)->toBeNull()
        ->and($brandKit->fresh()?->user_id)->toBeNull()
        ->and($advertisingKit->fresh()?->user_id)->toBeNull()
        ->and($invitation->fresh()?->invited_by_user_id)->toBeNull()
        ->and($outgoingAccountInvitation->fresh()?->invited_by_user_id)->toBeNull()
        ->and($incomingAccountInvitation->fresh())->toBeNull()
        ->and($validationRun->fresh())->not->toBeNull()
        ->and($validationRun->fresh()?->user_id)->toBeNull()
        ->and($audit->fresh())->not->toBeNull()
        ->and($audit->fresh()?->actor_user_id)->toBe($departingUser->id)
        ->and($contributedPhoto->fresh())->toBeNull()
        ->and($initiatedBatch->fresh())->toBeNull()
        ->and($initiatedOutput->fresh())->toBeNull()
        ->and($derivedBatch->fresh())->toBeNull()
        ->and($derivedOutput->fresh())->toBeNull()
        ->and($authoredConversation->fresh())->toBeNull()
        ->and($authoredMessage->fresh())->toBeNull()
        ->and($participatedConversation->fresh())->toBeNull()
        ->and($participatedMessage->fresh())->toBeNull()
        ->and($survivingPhoto->fresh())->not->toBeNull()
        ->and($survivingConversation->fresh())->not->toBeNull()
        ->and($survivingMessage->fresh())->not->toBeNull()
        ->and(DB::table('account_user')->where('user_id', $departingUser->id)->exists())->toBeFalse();

    Storage::disk('s3')->assertMissing($contributedPhoto->path);
    Storage::disk('s3')->assertMissing($initiatedOutput->path);
    Storage::disk('s3')->assertMissing($derivedOutput->path);
    Storage::disk('s3')->assertExists($survivingPhoto->path);
});

test('sole owned workspaces stop at a durable workspace erasure handoff', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $this->actingAs($user);

    app(EraseUserAccount::class)->handle($user);
    advanceUserErasureToWorkspaceHandoff($user->id);

    $revision = DB::table('account_erasure_progress')->where('user_id', $user->id)->value('revision');
    (new EraseUserAccountData($user->id))->handle(app(EraseUserAccount::class));

    expect($user->fresh())->not->toBeNull()
        ->and($account->fresh())->not->toBeNull()
        ->and(DB::table('account_erasure_progress')->where('user_id', $user->id)->value('phase'))->toBe('workspace_erasure')
        ->and(DB::table('account_erasure_progress')->where('user_id', $user->id)->value('revision'))->toBe($revision)
        ->and(DB::table('account_erasure_targets')
            ->where('user_id', $user->id)
            ->where('resource_type', 'account')
            ->where('resource_id', $account->id)
            ->exists())->toBeTrue();
});

test('shared account owners must transfer ownership before erasure starts', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    DB::table('account_user')->insert([
        'account_id' => $account->id,
        'user_id' => $member->id,
        'role' => AccountRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->actingAs($owner);

    expect(fn () => app(EraseUserAccount::class)->handle($owner))
        ->toThrow(AccountErasureFailed::class, 'Transfer ownership');

    expect($owner->fresh()?->account_erasure_started_at)->toBeNull()
        ->and(DB::table('account_erasure_progress')->where('user_id', $owner->id)->exists())->toBeFalse()
        ->and(DB::table('account_erasure_targets')->where('user_id', $owner->id)->exists())->toBeFalse();
});

test('required contribution foreign keys prevent bypassing creator safe erasure', function () {
    $companyOwner = User::factory()->create();
    $departingUser = User::factory()->create();
    $companyAccount = $companyOwner->currentAccount;
    DB::table('account_user')->insert([
        'account_id' => $companyAccount->id,
        'user_id' => $departingUser->id,
        'role' => AccountRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $project = Project::factory()->for($departingUser, 'owner')->create(['account_id' => $companyAccount->id]);
    $photo = Photo::factory()->for($project)->for($departingUser)->create();

    $departingUser->currentAccount()->delete();
    DB::table('account_user')->where('user_id', $departingUser->id)->delete();

    expect(fn () => $departingUser->delete())->toThrow(QueryException::class)
        ->and($departingUser->fresh())->not->toBeNull()
        ->and($photo->fresh())->not->toBeNull()
        ->and($project->fresh())->not->toBeNull();
});

test('late private and membership references rewind to deterministic cleanup phases', function () {
    $companyOwner = User::factory()->create();
    $departingUser = User::factory()->create();
    $personalAccount = $departingUser->currentAccount;
    $companyAccount = $companyOwner->currentAccount;
    DB::table('account_user')->insert([
        'account_id' => $companyAccount->id,
        'user_id' => $departingUser->id,
        'role' => AccountRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $project = Project::factory()->for($companyOwner, 'owner')->create();
    $this->actingAs($departingUser);

    app(EraseUserAccount::class)->handle($departingUser);
    advanceUserErasureToWorkspaceHandoff($departingUser->id);
    app(EraseUserAccount::class)->resume($departingUser->id);
    $workspaceProgress = WorkspaceErasureProgress::query()->where('account_id', $personalAccount->id)->sole();
    (new EraseWorkspaceData($personalAccount->id, (string) $workspaceProgress->dispatch_token))
        ->handle(app(WorkspaceErasureService::class));
    app(EraseUserAccount::class)->resume($departingUser->id);

    expect(DB::table('account_erasure_progress')->where('user_id', $departingUser->id)->value('phase'))->toBe('finish');

    $lateConversation = AgentConversation::query()->create([
        'account_id' => $companyAccount->id,
        'user_id' => $departingUser->id,
        'title' => 'In-flight conversation',
    ]);
    $lateMessage = $lateConversation->messages()->create([
        'user_id' => $departingUser->id,
        'agent' => 'advisor',
        'role' => 'user',
        'content' => 'Late private context',
        'attachments' => [],
        'tool_calls' => [],
        'tool_results' => [],
        'usage' => [],
        'meta' => [],
    ]);
    $accountInvitation = AccountInvitation::factory()
        ->for($companyAccount)
        ->for($companyOwner, 'inviter')
        ->create(['email' => $departingUser->email]);
    $projectInvitation = ProjectInvitation::factory()
        ->for($project)
        ->for($companyOwner, 'inviter')
        ->create(['email' => $departingUser->email]);

    DB::table('account_invitations')->where('id', $accountInvitation->id)->update([
        'accepted_by_user_id' => $departingUser->id,
        'accepted_at' => now(),
    ]);
    DB::table('project_invitations')->where('id', $projectInvitation->id)->update([
        'accepted_by_user_id' => $departingUser->id,
        'accepted_at' => now(),
    ]);
    DB::table('account_user')->insert([
        'account_id' => $companyAccount->id,
        'user_id' => $departingUser->id,
        'role' => AccountRole::Member->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('project_user')->insert([
        'project_id' => $project->id,
        'user_id' => $departingUser->id,
        'permission' => 'write',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new EraseUserAccountData($departingUser->id))->handle(app(EraseUserAccount::class));

    expect($departingUser->fresh())->toBeNull()
        ->and($companyAccount->fresh())->not->toBeNull()
        ->and($project->fresh())->not->toBeNull()
        ->and($lateConversation->fresh())->toBeNull()
        ->and($lateMessage->fresh())->toBeNull()
        ->and($accountInvitation->fresh())->toBeNull()
        ->and($projectInvitation->fresh())->toBeNull()
        ->and(DB::table('account_user')->where('user_id', $departingUser->id)->exists())->toBeFalse()
        ->and(DB::table('project_user')->where('user_id', $departingUser->id)->exists())->toBeFalse();
});

test('pending erasure fences advisor and invitation acceptance writes', function () {
    $accountOwner = User::factory()->create();
    $recipient = User::factory()->create(['account_erasure_started_at' => now()]);
    $project = Project::factory()->for($accountOwner, 'owner')->create();
    $accountToken = 'known-account-token';
    $projectToken = 'known-project-token';
    $accountInvitation = AccountInvitation::factory()
        ->for($accountOwner->currentAccount)
        ->for($accountOwner, 'inviter')
        ->create([
            'email' => $recipient->email,
            'token_hash' => hash('sha256', $accountToken),
        ]);
    $projectInvitation = ProjectInvitation::factory()
        ->for($project)
        ->for($accountOwner, 'inviter')
        ->create([
            'email' => $recipient->email,
            'token_hash' => hash('sha256', $projectToken),
        ]);

    expect(fn () => app(AdvisorAnswerService::class)->conversationFor($recipient))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptAccountInvitation::class)->handle($accountInvitation, $recipient, $accountToken))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(AcceptProjectInvitation::class)->handle($projectInvitation, $recipient, $projectToken))
        ->toThrow(AuthorizationException::class);

    expect($accountInvitation->fresh()?->accepted_at)->toBeNull()
        ->and($projectInvitation->fresh()?->accepted_at)->toBeNull()
        ->and(DB::table('account_user')->where('user_id', $recipient->id)->where('account_id', $accountOwner->current_account_id)->exists())->toBeFalse()
        ->and(DB::table('project_user')->where('user_id', $recipient->id)->where('project_id', $project->id)->exists())->toBeFalse();
});
