<?php

use App\Actions\Accounts\CreateAccountInvitation;
use App\Actions\Accounts\RemoveAccountMember;
use App\Actions\Accounts\SwitchCurrentAccount;
use App\Actions\Accounts\TransferAccountOwnership;
use App\Actions\Accounts\UpdateAccountMemberRole;
use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Livewire\Settings\Account as WorkspaceSettings;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Models\WorkspaceErasureProgress;
use App\Notifications\AccountInvitationNotification;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();
});

function joinWorkspace(Account $account, User $user, AccountRole $role, bool $makeCurrent = true): void
{
    $account->members()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);

    if ($makeCurrent) {
        $user->forceFill(['current_account_id' => $account->id])->save();
    }
}

function invitationToken(): string
{
    $plainTextToken = null;

    Notification::assertSentOnDemand(
        AccountInvitationNotification::class,
        function (AccountInvitationNotification $notification) use (&$plainTextToken): bool {
            $plainTextToken = $notification->plainTextToken;

            return true;
        },
    );

    expect($plainTextToken)->toBeString();

    return $plainTextToken;
}

/** @param Closure(): string $operation */
function workspaceAdministrationForkedOperation(
    string $readyPath,
    string $startPath,
    string $resultPath,
    Closure $operation,
): int {
    $processId = pcntl_fork();

    if ($processId === -1) {
        throw new RuntimeException('The workspace administration concurrency test could not fork.');
    }

    if ($processId !== 0) {
        return $processId;
    }

    try {
        DB::disconnect();
        DB::reconnect();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 2');
        file_put_contents($readyPath, 'ready');
        $deadline = microtime(true) + 5;

        while (! file_exists($startPath) && microtime(true) < $deadline) {
            usleep(1000);
        }

        if (! file_exists($startPath)) {
            throw new RuntimeException('The workspace administration start barrier timed out.');
        }

        file_put_contents($resultPath, json_encode(['result' => $operation()], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($resultPath, json_encode([
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR));
        exit(1);
    }
}

function workspaceAdministrationPauseAfterLegacyActorLock(string $ownPath, string $peerPath): void
{
    $paused = false;

    DB::listen(function (QueryExecuted $query) use (&$paused, $ownPath, $peerPath): void {
        if ($paused || preg_match(
            '/from\s+[`"]?users[`"]?\s+where\s+[`"]?users[`"]?\.[`"]?id[`"]?\s*=\s*\?\s+limit\s+1\s+for\s+update/i',
            $query->sql,
        ) !== 1) {
            return;
        }

        $paused = true;
        file_put_contents($ownPath, 'locked');
        $deadline = microtime(true) + 5;

        while (! file_exists($peerPath) && microtime(true) < $deadline) {
            usleep(1000);
        }

        if (! file_exists($peerPath)) {
            throw new RuntimeException('The reciprocal legacy actor-lock barrier timed out.');
        }
    });
}

test('workspace settings render the selected account and navigation', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->get(route('account.edit'))
        ->assertOk()
        ->assertSee($owner->currentAccount->name)
        ->assertSee('Your workspaces')
        ->assertSee('Workspace');
});

test('only owners can see and start throttled secure workspace deletion', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $account = $owner->currentAccount;
    joinWorkspace($account, $admin, AccountRole::Admin);

    Livewire::actingAs($admin)
        ->test(WorkspaceSettings::class)
        ->assertDontSee('Permanently delete workspace')
        ->call('deleteWorkspace')
        ->assertForbidden();

    Livewire::actingAs($owner)
        ->test(WorkspaceSettings::class)
        ->assertSee('Permanently delete workspace')
        ->set('workspaceName', $account->name)
        ->set('currentPassword', 'password')
        ->call('deleteWorkspace')
        ->assertRedirect(route('dashboard'));

    expect($account->fresh()?->erasure_started_at)->not->toBeNull()
        ->and(WorkspaceErasureProgress::query()->where('account_id', $account->id)->exists())->toBeTrue()
        ->and(DB::table('account_user')->where('account_id', $account->id)->exists())->toBeFalse()
        ->and($owner->fresh())->not->toBeNull()
        ->and($admin->fresh())->not->toBeNull();
});

test('workspace deletion requires the exact name and rate limits repeated attempts', function () {
    Queue::fake();
    $owner = User::factory()->create();
    $component = Livewire::actingAs($owner)
        ->test(WorkspaceSettings::class)
        ->set('workspaceName', 'not-the-workspace-name')
        ->set('currentPassword', 'password');

    $component->call('deleteWorkspace')->assertHasErrors('workspaceName');
    $component->call('deleteWorkspace')->assertHasErrors('workspaceName');
    $component->call('deleteWorkspace')->assertHasErrors('workspaceName');
    $component->call('deleteWorkspace')->assertHasErrors('workspaceDeletion');

    expect($owner->currentAccount->fresh()?->erasure_started_at)->toBeNull()
        ->and(WorkspaceErasureProgress::query()->exists())->toBeFalse();
});

test('owners and administrators invite only the roles they are allowed to grant', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    joinWorkspace($account, $admin, AccountRole::Admin);
    joinWorkspace($account, $member, AccountRole::Member);
    $createInvitation = app(CreateAccountInvitation::class);

    $ownerInvitation = $createInvitation->handle($account, $owner, ' ADMIN@Example.com ', AccountRole::Admin);
    $adminInvitation = $createInvitation->handle($account, $admin, 'member@example.com', AccountRole::Member);

    expect($ownerInvitation->email)->toBe('admin@example.com')
        ->and($ownerInvitation->role)->toBe(AccountRole::Admin)
        ->and($ownerInvitation->token_hash)->toHaveLength(64)
        ->and($ownerInvitation->public_id)->toHaveLength(40)
        ->and($adminInvitation->role)->toBe(AccountRole::Member)
        ->and(fn () => $createInvitation->handle($account, $admin, 'another@example.com', AccountRole::Admin))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $createInvitation->handle($account, $member, 'blocked@example.com', AccountRole::Member))
        ->toThrow(AuthorizationException::class);
});

test('duplicate invitations refresh one unique record and invalidate the old token', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $createInvitation = app(CreateAccountInvitation::class);
    $invitation = $createInvitation->handle($account, $owner, 'Recipient@example.com', AccountRole::Member);
    $firstToken = invitationToken();

    Notification::fake();
    $this->travel(61)->seconds();

    $refreshed = $createInvitation->handle($account, $owner, ' recipient@example.com ', AccountRole::Admin);
    $secondToken = invitationToken();

    expect($account->invitations()->count())->toBe(1)
        ->and($refreshed->is($invitation))->toBeTrue()
        ->and($refreshed->tokenMatches($firstToken))->toBeFalse()
        ->and($refreshed->tokenMatches($secondToken))->toBeTrue()
        ->and($refreshed->role)->toBe(AccountRole::Admin);

    expect(fn () => AccountInvitation::factory()->for($account)->for($owner, 'inviter')->create([
        'email' => 'recipient@example.com',
    ]))->toThrow(QueryException::class);
});

test('invitation queue payloads encrypt the recipient and bearer token', function () {
    Notification::fake(false);
    $owner = User::factory()->create();
    $email = 'private-workspace-recipient@example.com';
    $token = 'workspace-token-that-must-not-appear-in-queue-payload';
    $invitation = AccountInvitation::factory()
        ->for($owner->currentAccount)
        ->for($owner, 'inviter')
        ->create(['email' => $email, 'token_hash' => hash('sha256', $token)]);
    $notification = new AccountInvitationNotification($invitation, $token);
    $notifiable = (new AnonymousNotifiable)->route('mail', $email);
    $queuedNotification = new SendQueuedNotifications(collect([$notifiable]), $notification, ['mail']);

    app(QueueFactory::class)->connection('database')->push($queuedNotification);

    $payload = DB::table('jobs')->value('payload');

    expect($payload)->toBeString()
        ->not->toContain($email)
        ->not->toContain($token)
        ->and($queuedNotification->shouldBeEncrypted)->toBeTrue()
        ->and($queuedNotification->deleteWhenMissingModels)->toBeTrue();
});

test('a verified matching recipient accepts once preserves their personal workspace and switches context', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'Recipient@Example.com']);
    $personalAccountId = $recipient->current_account_id;
    $invitation = app(CreateAccountInvitation::class)->handle(
        $owner->currentAccount,
        $owner,
        ' recipient@example.com ',
        AccountRole::Member,
    );
    $token = invitationToken();
    $acceptUrl = URL::temporarySignedRoute(
        'account-invitations.show',
        $invitation->expires_at,
        ['accountInvitation' => $invitation, 'token' => $token],
    );

    $this->actingAs($recipient)->get($acceptUrl)->assertOk()->assertSee($owner->currentAccount->name);
    $oldSessionId = session()->getId();

    $this->post($acceptUrl)->assertRedirect(route('dashboard'));

    expect($recipient->refresh()->accounts()->pluck('accounts.id')->all())
        ->toContain($personalAccountId, $owner->current_account_id)
        ->and($recipient->current_account_id)->toBe($owner->current_account_id)
        ->and($owner->currentAccount->roleFor($recipient))->toBe(AccountRole::Member)
        ->and($invitation->fresh()->accepted_by_user_id)->toBe($recipient->id)
        ->and(session()->getId())->not->toBe($oldSessionId);

    $this->post($acceptUrl)->assertGone();
});

test('an invited new user can register verify and return to the signed acceptance flow', function () {
    $owner = User::factory()->create();
    $invitation = app(CreateAccountInvitation::class)->handle(
        $owner->currentAccount,
        $owner,
        'new-company-user@example.com',
        AccountRole::Member,
    );
    $token = invitationToken();
    $acceptUrl = URL::temporarySignedRoute(
        'account-invitations.show',
        $invitation->expires_at,
        ['accountInvitation' => $invitation, 'token' => $token],
    );

    $this->get($acceptUrl)->assertRedirect(route('login'));
    $registrationAcceptUrl = session()->get('url.intended');

    expect($registrationAcceptUrl)->toBeString()
        ->and(parse_url($registrationAcceptUrl, PHP_URL_PATH))->toBe(parse_url($acceptUrl, PHP_URL_PATH));

    $this->post(route('register.store'), [
        'name' => 'New Company User',
        'email' => 'new-company-user@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect($registrationAcceptUrl);

    $recipient = User::query()->where('email', 'new-company-user@example.com')->sole();

    $this->get($registrationAcceptUrl)->assertRedirect(route('verification.notice'));
    $verifiedAcceptUrl = session()->get('url.intended');

    expect($verifiedAcceptUrl)->toBeString()
        ->and(parse_url($verifiedAcceptUrl, PHP_URL_PATH))->toBe(parse_url($acceptUrl, PHP_URL_PATH));

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addHour(),
        ['id' => $recipient->id, 'hash' => sha1($recipient->email)],
    );

    $this->get($verificationUrl)->assertRedirect($verifiedAcceptUrl);
    $this->get($verifiedAcceptUrl)->assertOk();
    $this->post($verifiedAcceptUrl)->assertRedirect(route('dashboard'));

    expect($recipient->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($recipient->accounts()->count())->toBe(2)
        ->and($recipient->current_account_id)->toBe($owner->current_account_id);
});

test('invitation acceptance rejects unsigned mismatched unverified expired and revoked requests', function (string $state) {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $invitation = app(CreateAccountInvitation::class)->handle(
        $owner->currentAccount,
        $owner,
        $recipient->email,
        AccountRole::Member,
    );
    $token = invitationToken();
    $acceptUrl = URL::temporarySignedRoute(
        'account-invitations.show',
        now()->addDay(),
        ['accountInvitation' => $invitation, 'token' => $token],
    );

    if ($state === 'unsigned') {
        $acceptUrl = route('account-invitations.accept', ['accountInvitation' => $invitation, 'token' => $token]);
    } elseif ($state === 'mismatch') {
        $recipient = User::factory()->create(['email' => 'different@example.com']);
    } elseif ($state === 'unverified') {
        $recipient->forceFill(['email_verified_at' => null])->save();
    } elseif ($state === 'expired') {
        $invitation->update(['expires_at' => now()->subMinute()]);
    } else {
        $invitation->update(['revoked_at' => now()]);
    }

    $response = $this->actingAs($recipient)->post($acceptUrl);

    if ($state === 'unverified') {
        $response->assertRedirect(route('verification.notice'));
    } elseif (in_array($state, ['expired', 'revoked'], true)) {
        $response->assertGone();
    } else {
        $response->assertForbidden();
    }

    expect($invitation->fresh()->accepted_at)->toBeNull();
})->with(['unsigned', 'mismatch', 'unverified', 'expired', 'revoked']);

test('forged cross-account livewire identifiers are never trusted', function () {
    $owner = User::factory()->create();
    $foreignOwner = User::factory()->create();
    $foreignMember = User::factory()->create();
    joinWorkspace($foreignOwner->currentAccount, $foreignMember, AccountRole::Member);
    $foreignInvitation = AccountInvitation::factory()
        ->for($foreignOwner->currentAccount)
        ->for($foreignOwner, 'inviter')
        ->create();

    $this->actingAs($owner);

    Livewire::test(WorkspaceSettings::class)
        ->call('removeMember', $foreignMember->id)
        ->assertNotFound();
    Livewire::test(WorkspaceSettings::class)
        ->call('revokeInvitation', $foreignInvitation->public_id)
        ->assertNotFound();
    Livewire::test(WorkspaceSettings::class)
        ->call('switchAccount', $foreignOwner->current_account_id)
        ->assertNotFound();

    expect($foreignOwner->currentAccount->isMember($foreignMember))->toBeTrue()
        ->and($foreignInvitation->fresh()->revoked_at)->toBeNull();
});

test('the membership role and removal matrix is enforced server side', function () {
    $owner = User::factory()->create(['password' => Hash::make('password')]);
    $admin = User::factory()->create();
    $otherAdmin = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    joinWorkspace($account, $admin, AccountRole::Admin);
    joinWorkspace($account, $otherAdmin, AccountRole::Admin);
    joinWorkspace($account, $member, AccountRole::Member);

    $this->actingAs($owner);
    app(UpdateAccountMemberRole::class)->handle($account, $owner, $member, AccountRole::Admin, 'password');
    expect($account->roleFor($member))->toBe(AccountRole::Admin);

    expect(fn () => app(UpdateAccountMemberRole::class)->handle($account, $admin, $member, AccountRole::Member, 'password'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveAccountMember::class)->handle($account, $admin, $otherAdmin))
        ->toThrow(AuthorizationException::class);

    app(UpdateAccountMemberRole::class)->handle($account, $owner, $member, AccountRole::Member, 'password');
    app(RemoveAccountMember::class)->handle($account, $admin, $member);

    expect($account->isMember($member))->toBeFalse()
        ->and($account->isMember($otherAdmin))->toBeTrue();
});

test('sensitive role changes require a current or recently confirmed password', function () {
    $owner = User::factory()->create(['password' => Hash::make('correct-password')]);
    $member = User::factory()->create();
    joinWorkspace($owner->currentAccount, $member, AccountRole::Member);
    $this->actingAs($owner);

    expect(fn () => app(UpdateAccountMemberRole::class)->handle(
        $owner->currentAccount,
        $owner,
        $member,
        AccountRole::Admin,
        'wrong-password',
    ))->toThrow(ValidationException::class);

    $this->get(route('account.edit'))->assertOk();
    session()->put('auth.password_confirmed_at', time());
    app(UpdateAccountMemberRole::class)->handle(
        $owner->currentAccount,
        $owner,
        $member,
        AccountRole::Admin,
        null,
    );

    expect($owner->currentAccount->roleFor($member))->toBe(AccountRole::Admin);
});

test('removing the current membership selects another account or provisions a personal fallback', function (bool $hasExistingAccount) {
    $owner = User::factory()->create();
    $member = User::query()->create([
        'name' => 'Removed Member',
        'email' => fake()->unique()->safeEmail(),
        'email_verified_at' => now(),
        'password' => 'password',
    ]);
    $account = $owner->currentAccount;
    joinWorkspace($account, $member, AccountRole::Member);
    $existingAccount = null;

    if ($hasExistingAccount) {
        $existingAccount = Account::query()->create(['name' => 'Existing workspace']);
        $existingAccount->members()->attach($member, ['role' => AccountRole::Owner]);
        $existingAccount->entitlement()->create(['plan' => 'beta', 'status' => AccountEntitlementStatus::Active]);
    }

    app(RemoveAccountMember::class)->handle($account, $owner, $member);
    $replacement = $member->refresh()->currentAccount;

    expect($account->isMember($member))->toBeFalse()
        ->and($replacement)->not->toBeNull()
        ->and($replacement->roleFor($member))->toBe(AccountRole::Owner)
        ->and($replacement->entitlement->status)->toBe(
            $hasExistingAccount ? AccountEntitlementStatus::Active : AccountEntitlementStatus::Trialing,
        );

    if ($existingAccount instanceof Account) {
        expect($replacement->is($existingAccount))->toBeTrue();
    } else {
        expect($replacement->name)->toBe('Removed Member workspace');
    }
})->with(['existing account' => true, 'new personal fallback' => false]);

test('sole owners cannot leave be removed or change their owner role generically', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;

    expect(fn () => app(RemoveAccountMember::class)->handle($account, $owner, $owner))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateAccountMemberRole::class)->handle(
            $account,
            $owner,
            $owner,
            AccountRole::Member,
            'password',
        ))->toThrow(AuthorizationException::class)
        ->and($account->roleFor($owner))->toBe(AccountRole::Owner);
});

test('ownership transfer is atomic leaves exactly one owner and demotes the former owner', function () {
    $owner = User::factory()->create(['password' => Hash::make('password')]);
    $target = User::factory()->create();
    $account = $owner->currentAccount;
    joinWorkspace($account, $target, AccountRole::Member);
    $this->actingAs($owner);

    app(TransferAccountOwnership::class)->handle($account, $owner, $target, 'password');

    expect($account->roleFor($owner))->toBe(AccountRole::Admin)
        ->and($account->roleFor($target))->toBe(AccountRole::Owner)
        ->and(DB::table('account_user')->where('account_id', $account->id)->where('role', 'owner')->count())->toBe(1)
        ->and(fn () => app(TransferAccountOwnership::class)->handle($account, $owner, $target, null))
        ->toThrow(AuthorizationException::class);
});

test('reciprocal removals on distinct accounts serialize user locks without retries or deadlocks', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This concurrency test requires a database engine with row-level pessimistic locks.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This concurrency test requires the PCNTL extension.');
    }

    $databaseName = DB::connection()->getDatabaseName();

    if (preg_match('/(^|[_-])(test|testing)([_-]|$)/i', $databaseName) !== 1) {
        $this->markTestSkipped('This committed concurrency test requires an isolated test database.');
    }

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    $accountA = $ownerA->currentAccount;
    $accountB = $ownerB->currentAccount;
    joinWorkspace($accountA, $ownerB, AccountRole::Member, false);
    joinWorkspace($accountB, $ownerA, AccountRole::Member, false);
    $ownerAId = $ownerA->id;
    $ownerBId = $ownerB->id;
    $accountAId = $accountA->id;
    $accountBId = $accountB->id;
    DB::commit();

    $directory = sys_get_temp_dir().'/mentrovia-admin-locks-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $startPath = $directory.'/start';
    $removalAReadyPath = $directory.'/removal-a-ready';
    $removalBReadyPath = $directory.'/removal-b-ready';
    $legacyActorALockedPath = $directory.'/legacy-actor-a-locked';
    $legacyActorBLockedPath = $directory.'/legacy-actor-b-locked';
    $removalAResultPath = $directory.'/removal-a-result.json';
    $removalBResultPath = $directory.'/removal-b-result.json';

    $removalAProcessId = workspaceAdministrationForkedOperation(
        $removalAReadyPath,
        $startPath,
        $removalAResultPath,
        function () use (
            $accountAId,
            $ownerAId,
            $ownerBId,
            $legacyActorALockedPath,
            $legacyActorBLockedPath,
        ): string {
            $account = Account::query()->findOrFail($accountAId);
            $actor = User::query()->findOrFail($ownerAId);
            $target = User::query()->findOrFail($ownerBId);
            $transactionBegins = 0;
            Event::listen(TransactionBeginning::class, function () use (&$transactionBegins): void {
                $transactionBegins++;
            });
            workspaceAdministrationPauseAfterLegacyActorLock(
                $legacyActorALockedPath,
                $legacyActorBLockedPath,
            );
            app(RemoveAccountMember::class)->handle($account, $actor, $target);

            return "removed:{$transactionBegins}";
        },
    );
    $removalBProcessId = workspaceAdministrationForkedOperation(
        $removalBReadyPath,
        $startPath,
        $removalBResultPath,
        function () use (
            $accountBId,
            $ownerAId,
            $ownerBId,
            $legacyActorALockedPath,
            $legacyActorBLockedPath,
        ): string {
            $account = Account::query()->findOrFail($accountBId);
            $actor = User::query()->findOrFail($ownerBId);
            $target = User::query()->findOrFail($ownerAId);
            $transactionBegins = 0;
            Event::listen(TransactionBeginning::class, function () use (&$transactionBegins): void {
                $transactionBegins++;
            });
            workspaceAdministrationPauseAfterLegacyActorLock(
                $legacyActorBLockedPath,
                $legacyActorALockedPath,
            );
            app(RemoveAccountMember::class)->handle($account, $actor, $target);

            return "removed:{$transactionBegins}";
        },
    );

    $readyDeadline = microtime(true) + 5;

    while ((! file_exists($removalAReadyPath) || ! file_exists($removalBReadyPath))
        && microtime(true) < $readyDeadline) {
        usleep(1000);
    }

    file_put_contents($startPath, 'start');
    pcntl_waitpid($removalAProcessId, $removalAStatus);
    pcntl_waitpid($removalBProcessId, $removalBStatus);

    DB::disconnect();
    DB::reconnect();

    try {
        $removalAResult = json_decode((string) file_get_contents($removalAResultPath), true, flags: JSON_THROW_ON_ERROR);
        $removalBResult = json_decode((string) file_get_contents($removalBResultPath), true, flags: JSON_THROW_ON_ERROR);

        expect(file_exists($removalAReadyPath))->toBeTrue()
            ->and(file_exists($removalBReadyPath))->toBeTrue()
            ->and(pcntl_wifexited($removalAStatus))->toBeTrue()
            ->and(pcntl_wexitstatus($removalAStatus))->toBe(0)
            ->and(pcntl_wifexited($removalBStatus))->toBeTrue()
            ->and(pcntl_wexitstatus($removalBStatus))->toBe(0)
            ->and($removalAResult['result'])->toBe('removed:1')
            ->and($removalBResult['result'])->toBe('removed:1')
            ->and(DB::table('account_user')->where('account_id', $accountAId)->count())->toBe(1)
            ->and(DB::table('account_user')->where('account_id', $accountAId)->where('user_id', $ownerAId)->where('role', AccountRole::Owner->value)->exists())->toBeTrue()
            ->and(DB::table('account_user')->where('account_id', $accountBId)->count())->toBe(1)
            ->and(DB::table('account_user')->where('account_id', $accountBId)->where('user_id', $ownerBId)->where('role', AccountRole::Owner->value)->exists())->toBeTrue();
    } finally {
        Account::query()->whereIn('id', [$accountAId, $accountBId])->delete();
        User::query()->whereIn('id', [$ownerAId, $ownerBId])->delete();

        foreach ([$startPath, $removalAReadyPath, $removalBReadyPath, $legacyActorALockedPath, $legacyActorBLockedPath, $removalAResultPath, $removalBResultPath] as $path) {
            @unlink($path);
        }

        @rmdir($directory);
    }
});

test('switching requires a live membership refreshes the scoped resolver and changes the session', function () {
    $user = User::factory()->create();
    $originalAccount = $user->currentAccount;
    $secondAccount = Account::query()->create(['name' => 'Second workspace']);
    joinWorkspace($secondAccount, $user, AccountRole::Owner, false);
    $currentAccount = app(CurrentAccount::class);
    $currentAccount->resolve($user);
    $this->actingAs($user)->get(route('account.edit'))->assertOk();
    $oldSessionId = session()->getId();

    Livewire::test(WorkspaceSettings::class)
        ->call('switchAccount', $secondAccount->id)
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->current_account_id)->toBe($secondAccount->id)
        ->and($currentAccount->resolve($user)->is($secondAccount))->toBeTrue()
        ->and($originalAccount->isMember($user))->toBeTrue()
        ->and(session()->getId())->not->toBe($oldSessionId);

    $foreignAccount = User::factory()->create()->currentAccount;

    expect(fn () => app(SwitchCurrentAccount::class)->handle($user, $foreignAccount))
        ->toThrow(AuthorizationException::class);
});

test('a user erasure marker serialized after stale access blocks account switching without changing context', function () {
    $user = User::factory()->create();
    $originalAccount = $user->currentAccount;
    $secondAccount = Account::query()->create(['name' => 'Blocked switch workspace']);
    joinWorkspace($secondAccount, $user, AccountRole::Member, false);

    DB::table('users')->where('id', $user->id)->update(['account_erasure_started_at' => now()]);

    expect(fn () => app(SwitchCurrentAccount::class)->handle($user, $secondAccount))
        ->toThrow(AuthorizationException::class)
        ->and($user->refresh()->current_account_id)->toBe($originalAccount->id);
});
