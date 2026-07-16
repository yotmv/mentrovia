<?php

use App\Enums\AccountCapability;
use App\Enums\AccountRole;
use App\Enums\ProjectPermission;
use App\Models\Project;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

test('a stale manager authorization is rejected after demotion without a mutation', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($admin, ['role' => AccountRole::Admin]);
    $admin->forceFill(['current_account_id' => $account->id])->save();

    expect($admin->can('manageAi', $account))->toBeTrue();

    DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $admin->id)
        ->update(['role' => AccountRole::Member->value]);

    expect(fn () => DB::transaction(
        fn () => app(AccountMutationGate::class)->lockManagerOrFail($account->id, $admin->id),
    ))->toThrow(AuthorizationException::class);
});

test('a stale workspace authorization is rejected after membership removal', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();

    expect($account->isMember($member))->toBeTrue();

    DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $member->id)
        ->delete();

    expect(fn () => DB::transaction(
        fn () => app(AccountMutationGate::class)->lockMemberOrFail($account->id, $member->id),
    ))->toThrow(AuthorizationException::class);
});

test('an account erasure marker on the actor blocks every workspace mutation gate', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $user->forceFill(['account_erasure_started_at' => now()])->save();

    expect(fn () => DB::transaction(
        fn () => app(AccountMutationGate::class)->lockMemberOrFail($account->id, $user->id),
    ))->toThrow(AuthorizationException::class);
});

test('authorized members managers and exact project guests retain their intended mutation scope', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $guest = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $otherProject = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);
    $project->sharedUsers()->attach($guest, ['permission' => ProjectPermission::Write]);
    $gate = app(AccountMutationGate::class);

    $lockedMemberAccount = DB::transaction(
        fn () => $gate->lockMemberOrFail($account->id, $member->id, AccountCapability::Workspace),
    );
    $lockedManagerAccount = DB::transaction(
        fn () => $gate->lockManagerOrFail($account->id, $owner->id),
    );
    $lockedGuestProject = DB::transaction(
        fn () => $gate->lockProjectWriterOrFail($account->id, $project->id, $guest->id),
    );

    expect($lockedMemberAccount->is($account))->toBeTrue()
        ->and($lockedManagerAccount->is($account))->toBeTrue()
        ->and($lockedGuestProject->is($project))->toBeTrue()
        ->and(fn () => DB::transaction(
            fn () => $gate->lockProjectWriterOrFail($account->id, $otherProject->id, $guest->id),
        ))->toThrow(AuthorizationException::class)
        ->and(fn () => DB::transaction(
            fn () => $gate->lockProjectManagerOrFail($account->id, $project->id, $guest->id),
        ))->toThrow(AuthorizationException::class);
});

test('serialization queries follow account actor membership capability resource order', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $project = Project::factory()->for($owner, 'owner')->create(['account_id' => $account->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    DB::transaction(fn () => app(AccountMutationGate::class)->lockProjectMemberOrFail(
        $account->id,
        $project->id,
        $member->id,
        AccountCapability::Project,
    ));

    $queries = collect(DB::getQueryLog())->pluck('query')->map(fn (string $query): string => strtolower($query))->values();
    $positions = collect(['accounts', 'users', 'account_user', 'account_entitlements', 'projects'])
        ->map(fn (string $table): int|false => $queries->search(
            fn (string $query): bool => preg_match('/from\s+[`"]?'.preg_quote($table, '/').'[`"]?/i', $query) === 1,
        ));

    expect($positions->every(fn (int|false $position): bool => is_int($position)))->toBeTrue()
        ->and($positions->values()->all())->toBe(collect($positions)->sort()->values()->all());
});

test('multi-user administration locks every user once in ascending order before memberships', function () {
    $target = User::factory()->create();
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($target, ['role' => AccountRole::Member]);
    $target->forceFill(['current_account_id' => $account->id])->save();

    expect($owner->id)->toBeGreaterThan($target->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $context = DB::transaction(fn (): array => app(AccountMutationGate::class)->lockOwnerAndUsersOrFail(
        $account->id,
        $owner->id,
        [$target->id],
    ));

    $queries = collect(DB::getQueryLog())->values();
    $userLockQueries = $queries->filter(fn (array $query): bool => preg_match(
        '/from\s+[`"]?users[`"]?\s+where\s+[`"]?id[`"]?\s+in/i',
        $query['query'],
    ) === 1)->values();
    $accountLockPosition = $queries->search(fn (array $query): bool => preg_match(
        '/from\s+[`"]?accounts[`"]?\s+where\s+[`"]?accounts[`"]?\.[`"]?id[`"]?\s*=/i',
        $query['query'],
    ) === 1);
    $userLockPosition = $queries->search(fn (array $query): bool => preg_match(
        '/from\s+[`"]?users[`"]?\s+where\s+[`"]?id[`"]?\s+in/i',
        $query['query'],
    ) === 1);
    $membershipLockPosition = $queries->search(fn (array $query): bool => preg_match(
        '/from\s+[`"]?account_user[`"]?.*user_id[`"]?\s+in/i',
        $query['query'],
    ) === 1);
    $userLock = $userLockQueries->sole();

    expect($userLockQueries)->toHaveCount(1)
        ->and($accountLockPosition)->toBeInt()
        ->and($userLockPosition)->toBeInt()
        ->and($membershipLockPosition)->toBeInt()
        ->and($accountLockPosition)->toBeLessThan($userLockPosition)
        ->and($userLockPosition)->toBeLessThan($membershipLockPosition)
        ->and($userLock['query'])->toMatch('/order by\s+[`"]?id[`"]?\s+asc/i')
        ->and(array_map('intval', $userLock['bindings']))->toBe([$target->id, $owner->id])
        ->and($queries[$membershipLockPosition]['query'])->toMatch('/order by\s+[`"]?user_id[`"]?\s+asc/i')
        ->and($context['roles'][$owner->id])->toBe(AccountRole::Owner)
        ->and($context['roles'][$target->id])->toBe(AccountRole::Member);
});
