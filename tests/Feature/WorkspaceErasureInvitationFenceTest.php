<?php

use App\Actions\Accounts\AcceptAccountInvitation;
use App\Actions\Accounts\CreateAccountInvitation;
use App\Actions\Projects\AcceptProjectInvitation;
use App\Actions\Projects\CreateProjectInvitation;
use App\Actions\Users\EraseUserAccount;
use App\Enums\AccountRole;
use App\Enums\ProjectPermission;
use App\Exceptions\AccountErasureFailed;
use App\Models\Account;
use App\Models\AccountErasureTarget;
use App\Models\Project;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Notifications\ProjectInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

beforeEach(function () {
    Notification::fake();
    Queue::fake();
});

/** @return array{AccountInvitationNotification, string} */
function workspaceFenceAccountNotification(): array
{
    $captured = null;

    Notification::assertSentOnDemand(
        AccountInvitationNotification::class,
        function (AccountInvitationNotification $notification) use (&$captured): bool {
            $captured = $notification;

            return true;
        },
    );

    expect($captured)->toBeInstanceOf(AccountInvitationNotification::class);

    return [$captured, $captured->plainTextToken];
}

/** @return array{ProjectInvitationNotification, string} */
function workspaceFenceProjectNotification(): array
{
    $captured = null;

    Notification::assertSentOnDemand(
        ProjectInvitationNotification::class,
        function (ProjectInvitationNotification $notification) use (&$captured): bool {
            $captured = $notification;

            return true;
        },
    );

    expect($captured)->toBeInstanceOf(ProjectInvitationNotification::class);

    return [$captured, $captured->plainTextToken];
}

/** @param array<int, string> $queries @param array<int, string> $needles */
function workspaceFenceAssertSqlOrder(array $queries, array $needles): void
{
    $previousPosition = -1;

    foreach ($needles as $needle) {
        $position = null;

        foreach ($queries as $index => $query) {
            if ($index > $previousPosition && str_contains($query, $needle)) {
                $position = $index;

                break;
            }
        }

        expect($position, "Expected SQL after position {$previousPosition}: {$needle}")->not->toBeNull();
        $previousPosition = $position;
    }
}

/** @param Closure(): string $operation */
function workspaceFenceForkedOperation(
    string $readyPath,
    string $startPath,
    string $resultPath,
    Closure $operation,
): int {
    $processId = pcntl_fork();

    if ($processId === -1) {
        throw new RuntimeException('The concurrency test could not fork a child process.');
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
            throw new RuntimeException('The concurrency test start barrier timed out.');
        }

        file_put_contents($resultPath, json_encode([
            'result' => $operation(),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    } catch (Throwable $exception) {
        file_put_contents($resultPath, json_encode([
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR));
        exit(1);
    }
}

test('snapshotted workspace rejects pre-existing account and project invitation acceptance', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $account = $owner->currentAccount;
    $project = Project::factory()->for($owner, 'owner')->create();
    $accountInvitation = app(CreateAccountInvitation::class)->handle(
        $account,
        $owner,
        $recipient->email,
        AccountRole::Member,
    );
    [, $accountToken] = workspaceFenceAccountNotification();
    $projectInvitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        $recipient->email,
        ProjectPermission::Write,
    );
    [, $projectToken] = workspaceFenceProjectNotification();
    $recipientPersonalAccountId = $recipient->current_account_id;
    $this->actingAs($owner);

    app(EraseUserAccount::class)->handle($owner);

    expect(AccountErasureTarget::accountIsPendingErasure($account->id))->toBeTrue()
        ->and(fn () => app(AcceptAccountInvitation::class)->handle($accountInvitation, $recipient, $accountToken))
        ->toThrow(GoneHttpException::class)
        ->and(fn () => app(AcceptProjectInvitation::class)->handle($projectInvitation, $recipient, $projectToken))
        ->toThrow(GoneHttpException::class);

    expect($accountInvitation->fresh()?->accepted_at)->toBeNull()
        ->and($projectInvitation->fresh()?->accepted_at)->toBeNull()
        ->and(DB::table('account_user')->where('account_id', $account->id)->where('user_id', $recipient->id)->exists())->toBeFalse()
        ->and(DB::table('project_user')->where('project_id', $project->id)->where('user_id', $recipient->id)->exists())->toBeFalse()
        ->and($recipient->fresh()?->current_account_id)->toBe($recipientPersonalAccountId)
        ->and(AccountErasureTarget::query()
            ->where('user_id', $owner->id)
            ->where('resource_type', 'account')
            ->pluck('resource_id')->all())->toBe([$account->id]);
});

test('active workspace erasure rejects invitation creation and queued delivery', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $project = Project::factory()->for($owner, 'owner')->create();
    $accountInvitation = app(CreateAccountInvitation::class)->handle(
        $account,
        $owner,
        'queued-account@example.com',
        AccountRole::Member,
    );
    [$accountNotification] = workspaceFenceAccountNotification();
    $projectInvitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        'queued-project@example.com',
        ProjectPermission::Read,
    );
    [$projectNotification] = workspaceFenceProjectNotification();
    $this->actingAs($owner);

    app(EraseUserAccount::class)->handle($owner);
    DB::table('account_erasure_progress')->where('user_id', $owner->id)->delete();
    Notification::fake();

    expect(AccountErasureTarget::accountIsPendingErasure($account->id))->toBeTrue()
        ->and(fn () => app(CreateAccountInvitation::class)->handle(
            $account,
            $owner,
            'blocked-account@example.com',
            AccountRole::Member,
        ))->toThrow(GoneHttpException::class)
        ->and(fn () => app(CreateProjectInvitation::class)->handle(
            $project,
            $owner,
            'blocked-project@example.com',
            ProjectPermission::Read,
        ))->toThrow(GoneHttpException::class)
        ->and($accountNotification->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse()
        ->and($projectNotification->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();

    Notification::assertNothingSent();

    expect($account->invitations()->where('email', 'blocked-account@example.com')->exists())->toBeFalse()
        ->and($project->invitations()->where('email', 'blocked-project@example.com')->exists())->toBeFalse()
        ->and($accountInvitation->fresh())->not->toBeNull()
        ->and($projectInvitation->fresh())->not->toBeNull();
});

test('inactive and completed erasure targets do not fence normal invitations', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $account = $owner->currentAccount;
    $project = Project::factory()->for($owner, 'owner')->create();
    $staleTarget = AccountErasureTarget::query()->create([
        'user_id' => $owner->id,
        'resource_type' => 'account',
        'resource_id' => $account->id,
    ]);

    expect(AccountErasureTarget::accountIsPendingErasure($account->id))->toBeFalse();

    $accountInvitation = app(CreateAccountInvitation::class)->handle(
        $account,
        $owner,
        $recipient->email,
        AccountRole::Member,
    );
    [$accountNotification, $accountToken] = workspaceFenceAccountNotification();
    $staleTarget->delete();
    $projectInvitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        $recipient->email,
        ProjectPermission::Write,
    );
    [$projectNotification, $projectToken] = workspaceFenceProjectNotification();

    expect($accountNotification->shouldSend(new AnonymousNotifiable, 'mail'))->toBeTrue()
        ->and($projectNotification->shouldSend(new AnonymousNotifiable, 'mail'))->toBeTrue();

    app(AcceptProjectInvitation::class)->handle($projectInvitation, $recipient, $projectToken);
    app(AcceptAccountInvitation::class)->handle($accountInvitation, $recipient, $accountToken);

    expect($accountInvitation->fresh()?->accepted_by_user_id)->toBe($recipient->id)
        ->and($projectInvitation->fresh()?->accepted_by_user_id)->toBe($recipient->id)
        ->and(DB::table('account_user')->where('account_id', $account->id)->where('user_id', $recipient->id)->exists())->toBeTrue()
        ->and(DB::table('project_user')->where('project_id', $project->id)->where('user_id', $recipient->id)->exists())->toBeTrue();
});

test('invitation acceptance committed first prevents sole owner erasure from being snapshotted', function () {
    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'recipient@example.com']);
    $account = $owner->currentAccount;
    $invitation = app(CreateAccountInvitation::class)->handle(
        $account,
        $owner,
        $recipient->email,
        AccountRole::Member,
    );
    [, $token] = workspaceFenceAccountNotification();

    app(AcceptAccountInvitation::class)->handle($invitation, $recipient, $token);
    $this->actingAs($owner);

    expect(fn () => app(EraseUserAccount::class)->handle($owner))
        ->toThrow(AccountErasureFailed::class, 'Transfer ownership');

    expect($owner->fresh()?->account_erasure_started_at)->toBeNull()
        ->and(AccountErasureTarget::query()->where('user_id', $owner->id)->exists())->toBeFalse()
        ->and(DB::table('account_user')->where('account_id', $account->id)->where('user_id', $recipient->id)->exists())->toBeTrue();
});

test('marked inviters cannot create invitations in a surviving company workspace', function () {
    $companyOwner = User::factory()->create();
    $departingAdmin = User::factory()->create();
    $account = $companyOwner->currentAccount;
    DB::table('account_user')->insert([
        'account_id' => $account->id,
        'user_id' => $departingAdmin->id,
        'role' => AccountRole::Admin->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $departingAdmin->forceFill([
        'current_account_id' => $account->id,
        'account_erasure_started_at' => now(),
    ])->save();
    $project = Project::factory()->for($companyOwner, 'owner')->create();

    expect(AccountErasureTarget::accountIsPendingErasure($account->id))->toBeFalse()
        ->and(fn () => app(CreateAccountInvitation::class)->handle(
            $account,
            $departingAdmin,
            'blocked-account@example.com',
            AccountRole::Member,
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreateProjectInvitation::class)->handle(
            $project,
            $departingAdmin,
            'blocked-project@example.com',
            ProjectPermission::Read,
        ))->toThrow(AuthorizationException::class);

    Notification::assertNothingSent();

    expect($account->invitations()->exists())->toBeFalse()
        ->and($project->invitations()->exists())->toBeFalse();
});

test('erasure and invitation mutations acquire locks in the global account user resource order', function () {
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $erasableOwner = User::factory()->create();
    $erasableAccount = $erasableOwner->currentAccount;
    $this->actingAs($erasableOwner);
    $queries = [];

    app(EraseUserAccount::class)->handle($erasableOwner);

    workspaceFenceAssertSqlOrder($queries, [
        'select "id" from "accounts"',
        'select * from "users" where "users"."id" = ? limit 1',
        'select "account_id", "user_id", "role" from "account_user"',
        'insert into "account_erasure_targets"',
    ]);

    expect(AccountErasureTarget::accountIsPendingErasure($erasableAccount->id))->toBeTrue();

    $owner = User::factory()->create();
    $recipient = User::factory()->create(['email' => 'lock-order-recipient@example.com']);
    $account = $owner->currentAccount;
    $project = Project::factory()->for($owner, 'owner')->create();
    $project->setRelation('account', $account);
    $queries = [];

    $accountInvitation = app(CreateAccountInvitation::class)->handle(
        $account,
        $owner,
        $recipient->email,
        AccountRole::Member,
    );
    [, $accountToken] = workspaceFenceAccountNotification();

    workspaceFenceAssertSqlOrder($queries, [
        'select * from "accounts" where "accounts"."id" = ? limit 1',
        'select * from "users" where "users"."id" = ? limit 1',
        'select "role" from "account_user"',
        'insert into "account_invitations"',
    ]);

    $queries = [];
    $projectInvitation = app(CreateProjectInvitation::class)->handle(
        $project,
        $owner,
        $recipient->email,
        ProjectPermission::Write,
    );
    [, $projectToken] = workspaceFenceProjectNotification();

    workspaceFenceAssertSqlOrder($queries, [
        'select * from "accounts" where "accounts"."id" = ? limit 1',
        'select * from "users" where "users"."id" = ? limit 1',
        'select * from "projects" where "projects"."id" = ? limit 1',
        'insert into "project_invitations"',
    ]);

    $queries = [];
    app(AcceptProjectInvitation::class)->handle($projectInvitation, $recipient, $projectToken);

    workspaceFenceAssertSqlOrder($queries, [
        'select * from "accounts" where "accounts"."id" = ? limit 1',
        'select * from "users" where "users"."id" = ? limit 1',
        'select * from "projects" where "projects"."id" = ? limit 1',
        'select * from "project_invitations" where "project_invitations"."id" = ? limit 1',
        'insert into "project_user"',
    ]);

    $queries = [];
    app(AcceptAccountInvitation::class)->handle($accountInvitation, $recipient, $accountToken);

    workspaceFenceAssertSqlOrder($queries, [
        'select * from "accounts" where "accounts"."id" = ? limit 1',
        'select * from "users" where "users"."id" = ? limit 1',
        'select * from "account_invitations" where "account_invitations"."id" = ? limit 1',
        'select exists(select * from "account_user"',
        'insert into "account_user"',
    ]);
});

test('erasure restarts discovery when ownership appears before its user lock', function () {
    $owner = User::factory()->create();
    $originalAccount = $owner->currentAccount;
    $previousOwner = User::factory()->create();
    $newlyOwnedAccount = $previousOwner->currentAccount;
    $ownershipMoved = false;
    $accountLockSelections = 0;

    DB::listen(function (QueryExecuted $query) use (
        &$ownershipMoved,
        &$accountLockSelections,
        $owner,
        $previousOwner,
        $newlyOwnedAccount,
    ): void {
        if (! str_contains(strtolower($query->sql), 'select "id" from "accounts"')) {
            return;
        }

        $accountLockSelections++;

        if ($ownershipMoved) {
            return;
        }

        $ownershipMoved = true;
        DB::table('account_user')
            ->where('account_id', $newlyOwnedAccount->id)
            ->where('user_id', $previousOwner->id)
            ->delete();
        DB::table('account_user')->insert([
            'account_id' => $newlyOwnedAccount->id,
            'user_id' => $owner->id,
            'role' => AccountRole::Owner->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($owner);
    app(EraseUserAccount::class)->handle($owner);

    expect($ownershipMoved)->toBeTrue()
        ->and($accountLockSelections)->toBe(2)
        ->and(AccountErasureTarget::query()
            ->where('user_id', $owner->id)
            ->where('resource_type', 'account')
            ->orderBy('resource_id')
            ->pluck('resource_id')
            ->all())->toBe([$originalAccount->id, $newlyOwnedAccount->id])
        ->and($owner->fresh()?->account_erasure_started_at)->not->toBeNull();
});

test('invitation creation and erasure start serialize across two database connections', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('This concurrency test requires a database engine with row-level pessimistic locks.');
    }

    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This concurrency test requires the PCNTL extension.');
    }

    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $ownerId = $owner->id;
    $accountId = $account->id;
    DB::commit();

    $directory = sys_get_temp_dir().'/mentrovia-erasure-fence-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700, true);
    $startPath = $directory.'/start';
    $invitationReadyPath = $directory.'/invitation-ready';
    $erasureReadyPath = $directory.'/erasure-ready';
    $invitationResultPath = $directory.'/invitation-result.json';
    $erasureResultPath = $directory.'/erasure-result.json';

    $invitationProcessId = workspaceFenceForkedOperation(
        $invitationReadyPath,
        $startPath,
        $invitationResultPath,
        function () use ($ownerId, $accountId): string {
            Notification::fake();
            $owner = User::query()->findOrFail($ownerId);
            $account = $owner->accounts()->findOrFail($accountId);

            try {
                app(CreateAccountInvitation::class)->handle(
                    $account,
                    $owner,
                    'concurrent-recipient@example.com',
                    AccountRole::Member,
                );

                return 'created';
            } catch (GoneHttpException) {
                return 'fenced';
            }
        },
    );
    $erasureProcessId = workspaceFenceForkedOperation(
        $erasureReadyPath,
        $startPath,
        $erasureResultPath,
        function () use ($ownerId): string {
            Queue::fake();
            $owner = User::query()->findOrFail($ownerId);
            auth()->guard('web')->login($owner);
            app(EraseUserAccount::class)->handle($owner);

            return 'started';
        },
    );

    $readyDeadline = microtime(true) + 5;

    while ((! file_exists($invitationReadyPath) || ! file_exists($erasureReadyPath))
        && microtime(true) < $readyDeadline) {
        usleep(1000);
    }

    expect(file_exists($invitationReadyPath))->toBeTrue()
        ->and(file_exists($erasureReadyPath))->toBeTrue();

    file_put_contents($startPath, 'start');
    pcntl_waitpid($invitationProcessId, $invitationStatus);
    pcntl_waitpid($erasureProcessId, $erasureStatus);

    $invitationResult = json_decode((string) file_get_contents($invitationResultPath), true, flags: JSON_THROW_ON_ERROR);
    $erasureResult = json_decode((string) file_get_contents($erasureResultPath), true, flags: JSON_THROW_ON_ERROR);

    DB::disconnect();
    DB::reconnect();

    expect(pcntl_wifexited($invitationStatus))->toBeTrue()
        ->and(pcntl_wexitstatus($invitationStatus))->toBe(0)
        ->and(pcntl_wifexited($erasureStatus))->toBeTrue()
        ->and(pcntl_wexitstatus($erasureStatus))->toBe(0)
        ->and($invitationResult['result'])->toBeIn(['created', 'fenced'])
        ->and($erasureResult['result'])->toBe('started')
        ->and(User::query()->findOrFail($ownerId)->account_erasure_started_at)->not->toBeNull()
        ->and(AccountErasureTarget::accountIsPendingErasure($accountId))->toBeTrue();

    Account::query()->whereKey($accountId)->delete();
    User::query()->whereKey($ownerId)->delete();

    foreach ([$startPath, $invitationReadyPath, $erasureReadyPath, $invitationResultPath, $erasureResultPath] as $path) {
        @unlink($path);
    }

    @rmdir($directory);
});
