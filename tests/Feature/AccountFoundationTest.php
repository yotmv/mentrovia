<?php

use App\Actions\Accounts\CreatePersonalAccount;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Users\EraseUserAccount;
use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Exceptions\AccountErasureFailed;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\mock;

test('registration atomically provisions the personal workspace', function () {
    Notification::fake();

    $this->post(route('register.store'), [
        'name' => 'Ada Owner',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'ada@example.com')->sole();
    $account = $user->currentAccount()->sole();

    expect($user->accounts()->count())->toBe(1)
        ->and($account->roleFor($user))->toBe(AccountRole::Owner)
        ->and($account->entitlement->status)->toBe(AccountEntitlementStatus::Trialing)
        ->and($account->entitlement->plan)->toBe('standard')
        ->and($account->entitlement->trial_ends_at?->isFuture())->toBeTrue()
        ->and($account->trial_ends_at)->toBeNull();
});

test('registration rolls back the user when workspace provisioning fails', function () {
    $provisioner = mock(CreatePersonalAccount::class);
    $provisioner->shouldReceive('handle')->once()->andThrow(new RuntimeException('forced provisioning failure'));

    $action = new CreateNewUser($provisioner);

    expect(fn () => $action->create([
        'name' => 'Rollback Owner',
        'email' => 'rollback@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(RuntimeException::class, 'forced provisioning failure');

    expect(User::query()->where('email', 'rollback@example.com')->exists())->toBeFalse()
        ->and(Account::query()->exists())->toBeFalse()
        ->and(DB::table('account_user')->exists())->toBeFalse()
        ->and(DB::table('account_entitlements')->exists())->toBeFalse();
});

test('user factories provision one idempotent personal workspace and legacy factories inherit it', function () {
    $user = User::factory()->create();
    $originalAccountId = $user->current_account_id;

    app(CreatePersonalAccount::class)->handle($user);
    $business = Business::factory()->for($user)->create();
    $unsavedBusiness = Business::factory()->make(['user_id' => 31337]);

    expect(Account::query()->count())->toBe(1)
        ->and(DB::table('account_user')->count())->toBe(1)
        ->and($business->account_id)->toBe($originalAccountId)
        ->and($unsavedBusiness->account_id)->toBe(31337);
});

test('membership helpers preserve current account isolation', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $ownerAccount = $owner->currentAccount;
    $memberAccountId = $member->current_account_id;

    $ownerAccount->members()->attach($member, ['role' => AccountRole::Member]);

    expect($ownerAccount->isMember($member))->toBeTrue()
        ->and($ownerAccount->hasRole($owner, AccountRole::Owner))->toBeTrue()
        ->and($ownerAccount->roleFor($member))->toBe(AccountRole::Member)
        ->and($member->refresh()->current_account_id)->toBe($memberAccountId);
});

test('the database rejects a second account owner and the model rejects removing the last owner', function () {
    $owner = User::factory()->create();
    $secondUser = User::factory()->create();
    $account = $owner->currentAccount;

    expect(fn () => $account->members()->attach($secondUser, ['role' => AccountRole::Owner]))
        ->toThrow(QueryException::class);

    expect(fn () => $account->members()->detach($owner))
        ->toThrow(LogicException::class, 'Transfer or delete the account before removing its owner.');

    expect(fn () => $owner->delete())->toThrow(QueryException::class);

    expect($account->fresh())->not->toBeNull()
        ->and($owner->fresh())->not->toBeNull();
});

test('erasure fails closed when account ownership must be transferred', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $owner->currentAccount->members()->attach($member, ['role' => AccountRole::Member]);
    $this->actingAs($owner);

    expect(fn () => app(EraseUserAccount::class)->handle($owner))
        ->toThrow(AccountErasureFailed::class, 'Transfer ownership');

    expect($owner->fresh())->not->toBeNull()
        ->and($owner->fresh()->account_erasure_started_at)->toBeNull()
        ->and($owner->currentAccount()->exists())->toBeTrue();
});

test('account scoped uniqueness is enforced and permanent audit account ids remain unfenced', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    Business::factory()->for($first)->create();

    expect(fn () => Business::factory()->for($second)->create([
        'account_id' => $first->current_account_id,
    ]))->toThrow(QueryException::class);

    $audit = AiOperationAudit::query()->create([
        'operation_id' => fake()->uuid(),
        'account_id' => 999999,
        'event' => 'started',
    ]);

    expect($audit->refresh()->account_id)->toBe(999999);
});

test('legacy backfill is deterministic leaves audit ids unchanged and preflight fails closed', function () {
    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'account_foundation_migration';
    config(["database.connections.{$isolatedConnection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge($isolatedConnection);
    DB::setDefaultConnection($isolatedConnection);
    $schema = DB::connection()->getSchemaBuilder();

    $schema->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->unsignedBigInteger('current_account_id')->nullable();
        $table->timestamps();
    });
    $schema->create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    $schema->create('account_user', function (Blueprint $table): void {
        $table->unsignedBigInteger('account_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role');
        $table->timestamps();
        $table->primary(['account_id', 'user_id']);
    });
    $schema->create('account_entitlements', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id')->unique();
        $table->string('plan');
        $table->string('status');
        $table->timestamp('trial_ends_at')->nullable();
        $table->timestamps();
    });
    foreach (['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
        $schema->create($tableName, function (Blueprint $table) use ($tableName): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id')->nullable();
            if ($tableName === 'ai_provider_credentials') {
                $table->string('provider');
            }
            if ($tableName === 'ai_model_preferences') {
                $table->string('purpose');
            }
        });
    }
    $schema->create('agent_conversations', function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->unsignedBigInteger('account_id')->nullable();
    });
    $schema->create('ai_operation_audits', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('account_id')->nullable();
    });

    DB::table('users')->insert([
        ['id' => 7, 'name' => 'Seven', 'current_account_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ['id' => 19, 'name' => 'Nineteen', 'current_account_id' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    foreach (['businesses', 'projects', 'ai_account_settings'] as $tableName) {
        DB::table($tableName)->insert(['id' => 1, 'user_id' => 7, 'account_id' => null]);
    }
    DB::table('ai_provider_credentials')->insert(['id' => 1, 'user_id' => 7, 'account_id' => null, 'provider' => 'openrouter']);
    DB::table('ai_model_preferences')->insert(['id' => 1, 'user_id' => 7, 'account_id' => null, 'purpose' => 'image']);
    DB::table('agent_conversations')->insert(['id' => 'legacy', 'user_id' => 19, 'account_id' => null]);
    DB::table('ai_operation_audits')->insert(['id' => 1, 'account_id' => 31337]);

    try {
        $backfill = require database_path('migrations/2026_07_15_111732_backfill_account_workspaces.php');
        $backfill->up();
        $backfill->up();

        expect(DB::table('accounts')->orderBy('id')->pluck('id')->all())->toBe([7, 19])
            ->and(DB::table('businesses')->value('account_id'))->toBe(7)
            ->and(DB::table('agent_conversations')->value('account_id'))->toBe(19)
            ->and(DB::table('ai_operation_audits')->value('account_id'))->toBe(31337);

        DB::table('account_user')->insert(['account_id' => 7, 'user_id' => 19, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $preflight = require database_path('migrations/2026_07_15_111733_enforce_account_workspace_constraints.php');
        expect(fn () => $preflight->up())->toThrow(RuntimeException::class, 'accounts without one owner [7]');
    } finally {
        DB::disconnect($isolatedConnection);
        DB::purge($isolatedConnection);
        DB::setDefaultConnection($originalConnection);
    }
});
