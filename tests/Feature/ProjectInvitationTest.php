<?php

use App\Actions\Projects\CreateProjectInvitation;
use App\Enums\ProjectPermission;
use App\Livewire\Projects\Show;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
});

test('registered and unregistered addresses create indistinguishable pending invitations', function () {
    $owner = User::factory()->create();
    $registeredRecipient = User::factory()->create(['email' => 'known@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner);

    $knownResponse = Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'KNOWN@example.com')
        ->set('sharePermission', ProjectPermission::Write->value)
        ->call('share')
        ->assertHasNoErrors();

    $unknownResponse = Livewire::test(Show::class, ['project' => $project])
        ->set('shareEmail', 'unknown@example.com')
        ->set('sharePermission', ProjectPermission::Write->value)
        ->call('share')
        ->assertHasNoErrors();

    $knownInvitation = $project->invitations()->where('email', 'known@example.com')->sole();
    $unknownInvitation = $project->invitations()->where('email', 'unknown@example.com')->sole();

    expect($knownResponse->effects['dispatches'])->toEqual($unknownResponse->effects['dispatches'])
        ->and($knownInvitation->only(['permission', 'accepted_at', 'revoked_at']))
        ->toEqual($unknownInvitation->only(['permission', 'accepted_at', 'revoked_at']))
        ->and($knownInvitation->isPending())->toBeTrue()
        ->and($unknownInvitation->isPending())->toBeTrue()
        ->and($project->sharedUsers()->count())->toBe(0)
        ->and($project->isViewableBy($registeredRecipient))->toBeFalse();

    Notification::assertSentOnDemandTimes(ProjectInvitationNotification::class, 2);
});

test('invitation creation is rate limited per owner', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $createInvitation = app(CreateProjectInvitation::class);

    foreach (range(1, 10) as $attempt) {
        $createInvitation->handle(
            $project,
            $owner,
            "recipient-{$attempt}@example.com",
            ProjectPermission::Read,
        );
    }

    expect(fn () => $createInvitation->handle(
        $project,
        $owner,
        'rate-limited@example.com',
        ProjectPermission::Read,
    ))->toThrow(ValidationException::class);

    expect($project->invitations()->count())->toBe(10);
});

test('repeated invitations to the same project recipient observe a cooldown', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $createInvitation = app(CreateProjectInvitation::class);

    $invitation = $createInvitation->handle(
        $project,
        $owner,
        'Recipient@example.com',
        ProjectPermission::Read,
    );
    $originalTokenHash = $invitation->token_hash;

    expect(fn () => $createInvitation->handle(
        $project,
        $owner,
        ' recipient@example.com ',
        ProjectPermission::Write,
    ))->toThrow(
        ValidationException::class,
        'Too many invitations were sent. Please wait a minute and try again.',
    );

    expect($invitation->fresh()->token_hash)->toBe($originalTokenHash)
        ->and($invitation->fresh()->permission)->toBe(ProjectPermission::Read);

    Notification::assertSentOnDemandTimes(ProjectInvitationNotification::class, 1);
});

test('invitation queue payloads encrypt recipient addresses and bearer tokens', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $recipientEmail = 'private-recipient@example.com';
    $plainTextToken = 'invitation-token-that-must-never-be-queued-in-plaintext';
    $invitation = ProjectInvitation::factory()
        ->for($project)
        ->for($owner, 'inviter')
        ->create([
            'email' => $recipientEmail,
            'token_hash' => hash('sha256', $plainTextToken),
        ]);
    $notification = new ProjectInvitationNotification($invitation, $plainTextToken);
    $notifiable = (new AnonymousNotifiable)->route('mail', $recipientEmail);
    $queuedNotification = new SendQueuedNotifications(collect([$notifiable]), $notification, ['mail']);

    app(QueueFactory::class)->connection('database')->push($queuedNotification);

    $payload = DB::table('jobs')->value('payload');
    $decodedPayload = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    $restoredJob = unserialize(decrypt($decodedPayload['data']['command']));

    expect($payload)->toBeString();
    expect($payload)->not->toContain($recipientEmail);
    expect($payload)->not->toContain($plainTextToken);
    expect($queuedNotification->shouldBeEncrypted)->toBeTrue()
        ->and($queuedNotification->deleteWhenMissingModels)->toBeTrue()
        ->and($restoredJob)->toBeInstanceOf(SendQueuedNotifications::class)
        ->and($restoredJob->notification)->toBeInstanceOf(ProjectInvitationNotification::class);
});

test('a verified recipient can accept an invitation matching their normalized email', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'Recipient@Example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();

    $invitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        ' recipient@example.com ',
        ProjectPermission::Write,
    );

    $plainTextToken = null;

    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$plainTextToken): bool {
            $plainTextToken = $notification->plainTextToken;

            return true;
        },
    );

    expect($plainTextToken)->toBeString();

    $acceptUrl = URL::temporarySignedRoute(
        'project-invitations.show',
        $invitation->expires_at,
        ['projectInvitation' => $invitation, 'token' => $plainTextToken],
    );

    $this->actingAs($recipient)
        ->get($acceptUrl)
        ->assertOk()
        ->assertSee($project->name);

    $this->post($acceptUrl)
        ->assertRedirect(route('projects.show', $project));

    expect($project->sharedUsers()->sole()->is($recipient))->toBeTrue()
        ->and($project->isEditableBy($recipient))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($recipient->id);

    $this->post($acceptUrl)->assertStatus(410);
});

test('a different authenticated user cannot inspect or accept an invitation', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $foreignUser = User::factory()->create(['email' => 'foreign@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();

    $invitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        $recipient->email,
        ProjectPermission::Read,
    );

    $plainTextToken = null;
    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$plainTextToken): bool {
            $plainTextToken = $notification->plainTextToken;

            return true;
        },
    );

    $acceptUrl = URL::temporarySignedRoute(
        'project-invitations.show',
        $invitation->expires_at,
        ['projectInvitation' => $invitation, 'token' => $plainTextToken],
    );

    $this->actingAs($foreignUser)->get($acceptUrl)->assertForbidden();
    $this->post($acceptUrl)->assertForbidden();

    $invalidTokenUrl = URL::temporarySignedRoute(
        'project-invitations.show',
        $invitation->expires_at,
        ['projectInvitation' => $invitation, 'token' => 'invalid-token'],
    );

    $this->actingAs($recipient)->post($invalidTokenUrl)->assertForbidden();

    expect($project->sharedUsers()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('an unverified matching recipient must verify their email before accepting', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->unverified()->create(['email' => 'recipient@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();
    $invitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        $recipient->email,
        ProjectPermission::Read,
    );

    $plainTextToken = null;
    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$plainTextToken): bool {
            $plainTextToken = $notification->plainTextToken;

            return true;
        },
    );

    $acceptUrl = URL::temporarySignedRoute(
        'project-invitations.show',
        $invitation->expires_at,
        ['projectInvitation' => $invitation, 'token' => $plainTextToken],
    );

    $this->actingAs($recipient)
        ->post($acceptUrl)
        ->assertRedirect(route('verification.notice'));

    expect($project->sharedUsers()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
});

test('duplicate invitations refresh one pending record and invalidate the older token', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();
    $createInvitation = app(CreateProjectInvitation::class);

    $firstInvitation = $createInvitation->handle(
        $project,
        $owner,
        'Recipient@example.com',
        ProjectPermission::Read,
    );

    $firstToken = null;
    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$firstToken): bool {
            $firstToken = $notification->plainTextToken;

            return true;
        },
    );

    Notification::fake();

    $this->travel(61)->seconds();

    $refreshedInvitation = $createInvitation->handle(
        $project,
        $owner,
        ' recipient@example.com ',
        ProjectPermission::Write,
    );

    $secondToken = null;
    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$secondToken): bool {
            $secondToken = $notification->plainTextToken;

            return true;
        },
    );

    expect($project->invitations()->count())->toBe(1)
        ->and($refreshedInvitation->is($firstInvitation))->toBeTrue()
        ->and($refreshedInvitation->permission)->toBe(ProjectPermission::Write)
        ->and($refreshedInvitation->tokenMatches($firstToken))->toBeFalse()
        ->and($refreshedInvitation->tokenMatches($secondToken))->toBeTrue();

    $oldUrl = URL::temporarySignedRoute(
        'project-invitations.show',
        $refreshedInvitation->expires_at,
        ['projectInvitation' => $refreshedInvitation, 'token' => $firstToken],
    );

    $this->actingAs($recipient)->post($oldUrl)->assertForbidden();
});

test('queued invitation delivery is suppressed after revocation or token refresh', function (string $state) {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $invitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        'recipient@example.com',
        ProjectPermission::Read,
    );
    $queuedNotification = null;

    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$queuedNotification): bool {
            $queuedNotification = $notification;

            return true;
        },
    );

    if ($state === 'revoked') {
        $invitation->update(['revoked_at' => now()]);
    } else {
        $invitation->update(['token_hash' => hash('sha256', 'replacement-token')]);
    }

    expect($queuedNotification)->toBeInstanceOf(ProjectInvitationNotification::class)
        ->and($queuedNotification->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();
})->with(['revoked', 'refreshed']);

test('revoked and expired invitations cannot be accepted', function (string $unavailableState) {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $project = Project::factory()->for($owner, 'owner')->create();
    $invitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        $recipient->email,
        ProjectPermission::Read,
    );

    $plainTextToken = null;
    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$plainTextToken): bool {
            $plainTextToken = $notification->plainTextToken;

            return true;
        },
    );

    $acceptUrl = URL::temporarySignedRoute(
        'project-invitations.show',
        now()->addDay(),
        ['projectInvitation' => $invitation, 'token' => $plainTextToken],
    );

    if ($unavailableState === 'revoked') {
        $this->actingAs($owner);

        Livewire::test(Show::class, ['project' => $project])
            ->call('revokeInvitation', $invitation->public_id);
    } else {
        $invitation->update(['expires_at' => now()->subMinute()]);
    }

    $this->actingAs($recipient)->post($acceptUrl)->assertStatus(410);

    expect($project->sharedUsers()->count())->toBe(0)
        ->and($invitation->fresh()->accepted_at)->toBeNull();
})->with(['revoked', 'expired']);

test('a project owner cannot revoke an invitation belonging to another project', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $otherProject = Project::factory()->for($otherOwner, 'owner')->create();
    $foreignInvitation = ProjectInvitation::factory()
        ->for($otherProject)
        ->for($otherOwner, 'inviter')
        ->create();

    $this->actingAs($owner);

    Livewire::test(Show::class, ['project' => $project])
        ->call('revokeInvitation', $foreignInvitation->public_id)
        ->assertNotFound();

    expect($foreignInvitation->fresh()->revoked_at)->toBeNull();
});

test('stale terminal invitations are pruned while useful records are retained', function () {
    $staleAccepted = ProjectInvitation::factory()->create([
        'expires_at' => now()->addDay(),
        'accepted_at' => now()->subHours(25),
    ]);
    $staleRevoked = ProjectInvitation::factory()->create([
        'expires_at' => now()->addDay(),
        'revoked_at' => now()->subHours(25),
    ]);
    $expired = ProjectInvitation::factory()->create([
        'expires_at' => now()->subSecond(),
    ]);
    $recentlyAccepted = ProjectInvitation::factory()->create([
        'expires_at' => now()->addDay(),
        'accepted_at' => now()->subHours(23),
    ]);
    $recentlyRevoked = ProjectInvitation::factory()->create([
        'expires_at' => now()->addDay(),
        'revoked_at' => now()->subHours(23),
    ]);
    $pending = ProjectInvitation::factory()->create([
        'expires_at' => now()->addDay(),
    ]);

    Artisan::call('project-invitations:prune', ['--retention-hours' => 24]);

    $this->assertModelMissing($staleAccepted);
    $this->assertModelMissing($staleRevoked);
    $this->assertModelMissing($expired);
    $this->assertModelExists($recentlyAccepted);
    $this->assertModelExists($recentlyRevoked);
    $this->assertModelExists($pending);
});
