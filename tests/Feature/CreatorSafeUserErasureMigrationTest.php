<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('database.connections.creator_safe_rollback', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'creator_safe_rollback');
    DB::setDefaultConnection('creator_safe_rollback');
    DB::purge('creator_safe_rollback');

    Schema::create('users', fn (Blueprint $table) => $table->id());
    Schema::create('accounts', fn (Blueprint $table) => $table->id());
    Schema::create('businesses', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('account_id')->unique()->constrained()->cascadeOnDelete();
    });
    Schema::create('projects', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('account_id')->constrained()->cascadeOnDelete();
    });
    Schema::create('brand_kits', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    });
    Schema::create('advertising_kits', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    });
    Schema::create('project_invitations', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    });
    Schema::create('account_invitations', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    });
    Schema::create('validation_runs', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
    });
    Schema::create('photos', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->constrained()->restrictOnDelete();
    });
    Schema::create('photo_generation_batches', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->constrained()->restrictOnDelete();
    });
    Schema::create('agent_conversations', function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
    });
    Schema::create('agent_conversation_messages', function (Blueprint $table): void {
        $table->string('id', 36)->primary();
        $table->string('conversation_id', 36);
        $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
        $table->foreign('conversation_id')->references('id')->on('agent_conversations')->cascadeOnDelete();
    });
    Schema::create('photo_operation_leases', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->foreignId('initiating_user_id')->constrained()->restrictOnDelete();
        $table->foreignId('project_id')->constrained()->cascadeOnDelete();
        $table->timestamp('finished_at')->nullable();
        $table->timestamp('expires_at');
    });

    DB::table('users')->insert(['id' => 1]);
    DB::table('accounts')->insert([['id' => 1], ['id' => 2]]);
});

afterEach(function () {
    DB::purge('creator_safe_rollback');
    config()->set('database.default', 'sqlite');
    DB::setDefaultConnection('sqlite');
});

function creatorSafeForeignKeyMigration(): Migration
{
    return require database_path('migrations/2026_07_15_131332_enforce_creator_safe_user_erasure_foreign_keys.php');
}

function retiredLeaseAttributionMigration(): Migration
{
    return require database_path('migrations/2026_07_15_131332_retire_photo_lease_creator_attribution.php');
}

/** @return array<string, array<string, mixed>> */
function creatorSafeSchemaSnapshot(): array
{
    $snapshot = [];

    foreach ([
        'businesses',
        'projects',
        'brand_kits',
        'advertising_kits',
        'project_invitations',
        'account_invitations',
        'validation_runs',
        'photos',
        'photo_generation_batches',
        'agent_conversations',
        'agent_conversation_messages',
    ] as $tableName) {
        $snapshot[$tableName] = [
            'columns' => Schema::getColumns($tableName),
            'indexes' => Schema::getIndexes($tableName),
            'foreign_keys' => Schema::getForeignKeys($tableName),
        ];
    }

    return $snapshot;
}

test('creator safe rollback rejects missing legacy attribution before changing schema', function () {
    DB::table('businesses')->insert(['user_id' => null, 'account_id' => 1]);
    $before = creatorSafeSchemaSnapshot();

    expect(fn () => creatorSafeForeignKeyMigration()->down())
        ->toThrow(RuntimeException::class, 'Cannot restore required creator attribution for businesses.')
        ->and(creatorSafeSchemaSnapshot())->toEqual($before);
});

test('creator safe rollback rejects duplicate business creators before changing schema', function () {
    DB::table('businesses')->insert([
        ['user_id' => 1, 'account_id' => 1],
        ['user_id' => 1, 'account_id' => 2],
    ]);
    $before = creatorSafeSchemaSnapshot();

    expect(fn () => creatorSafeForeignKeyMigration()->down())
        ->toThrow(RuntimeException::class, 'Cannot restore unique business creator attribution [1].')
        ->and(creatorSafeSchemaSnapshot())->toEqual($before);
});

test('clean creator safe rollback restores legacy creator and contribution constraints', function () {
    creatorSafeForeignKeyMigration()->down();

    foreach ([
        'businesses' => 'user_id',
        'projects' => 'user_id',
        'brand_kits' => 'user_id',
        'advertising_kits' => 'user_id',
        'project_invitations' => 'invited_by_user_id',
        'account_invitations' => 'invited_by_user_id',
    ] as $tableName => $column) {
        $creatorColumn = collect(Schema::getColumns($tableName))->firstWhere('name', $column);
        $creatorForeignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]);

        expect($creatorColumn['nullable'])->toBeFalse()
            ->and(strtolower($creatorForeignKey['on_delete']))->toBe('cascade');
    }

    foreach (['photos', 'photo_generation_batches'] as $tableName) {
        $userForeignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']);

        expect(strtolower($userForeignKey['on_delete']))->toBe('cascade');
    }

    $validationBusinessForeignKey = collect(Schema::getForeignKeys('validation_runs'))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['business_id']);
    $conversationAccountForeignKey = collect(Schema::getForeignKeys('agent_conversations'))
        ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['account_id']);

    expect(strtolower($validationBusinessForeignKey['on_delete']))->toBe('set null')
        ->and(strtolower($conversationAccountForeignKey['on_delete']))->toBe('set null')
        ->and(collect(Schema::getForeignKeys('agent_conversations'))->pluck('columns')->all())->not->toContain(['user_id'])
        ->and(Schema::getForeignKeys('agent_conversation_messages'))->toBe([]);
});

test('lease attribution rollback restores only the retired legacy creator column', function () {
    retiredLeaseAttributionMigration()->down();

    $columns = collect(Schema::getColumns('photo_operation_leases'));
    $indexNames = collect(Schema::getIndexes('photo_operation_leases'))->pluck('name');

    expect($columns->firstWhere('name', 'project_owner_id')['nullable'])->toBeTrue()
        ->and($indexNames)->toContain('photo_operation_leases_project_owner_id_index')
        ->and($indexNames)->toContain('photo_leases_owner_active_index')
        ->and(Schema::getForeignKeys('photo_operation_leases'))->toBe([]);
});
