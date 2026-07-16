<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('database.connections.ai_scope_rollback', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'ai_scope_rollback');
    DB::setDefaultConnection('ai_scope_rollback');
    DB::purge('ai_scope_rollback');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
    });
    Schema::create('ai_account_settings', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('account_id')->constrained()->cascadeOnDelete();
        $table->unique('account_id');
    });
    Schema::create('ai_provider_credentials', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('account_id')->constrained()->cascadeOnDelete();
        $table->string('provider', 40);
        $table->unique(['account_id', 'provider']);
    });
    Schema::create('ai_model_preferences', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
        $table->foreignId('account_id')->constrained()->cascadeOnDelete();
        $table->string('purpose', 32);
        $table->unique(['account_id', 'purpose']);
    });

    DB::table('users')->insert(['id' => 1]);
    DB::table('accounts')->insert([['id' => 1], ['id' => 2]]);
});

afterEach(function () {
    DB::purge('ai_scope_rollback');
    config()->set('database.default', 'sqlite');
    DB::setDefaultConnection('sqlite');
});

function accountScopeTransitionMigration(): Migration
{
    return require database_path('migrations/2026_07_15_114922_transition_ai_controls_to_account_scope.php');
}

/** @return array<string, array<string, array<int, array<string, mixed>>>> */
function accountScopeSchemaSnapshot(): array
{
    $snapshot = [];

    foreach (['ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
        $snapshot[$tableName] = [
            'columns' => collect(Schema::getColumns($tableName))->sortBy('name')->values()->all(),
            'indexes' => collect(Schema::getIndexes($tableName))->sortBy('name')->values()->all(),
            'foreign_keys' => collect(Schema::getForeignKeys($tableName))
                ->sortBy(fn (array $foreignKey): string => implode(':', $foreignKey['columns']))
                ->values()
                ->all(),
        ];
    }

    return $snapshot;
}

test('rollback rejects null legacy attribution before changing any schema', function (
    string $tableName,
    array $attributes,
) {
    DB::table($tableName)->insert(['user_id' => null, 'account_id' => 1, ...$attributes]);
    $before = accountScopeSchemaSnapshot();

    expect(fn () => accountScopeTransitionMigration()->down())
        ->toThrow(RuntimeException::class, $tableName.' null user attribution');

    expect(accountScopeSchemaSnapshot())->toEqual($before);
})->with([
    'settings' => ['ai_account_settings', []],
    'credentials' => ['ai_provider_credentials', ['provider' => 'openrouter']],
    'preferences' => ['ai_model_preferences', ['purpose' => 'short_text']],
]);

test('rollback rejects duplicate legacy key shapes before changing any schema', function (
    string $tableName,
    array $attributes,
) {
    DB::table($tableName)->insert([
        ['user_id' => 1, 'account_id' => 1, ...$attributes],
        ['user_id' => 1, 'account_id' => 2, ...$attributes],
    ]);
    $before = accountScopeSchemaSnapshot();

    expect(fn () => accountScopeTransitionMigration()->down())
        ->toThrow(RuntimeException::class, $tableName.' duplicate legacy keys');

    expect(accountScopeSchemaSnapshot())->toEqual($before);
})->with([
    'settings user' => ['ai_account_settings', []],
    'credentials user and provider' => ['ai_provider_credentials', ['provider' => 'openrouter']],
    'preferences user and purpose' => ['ai_model_preferences', ['purpose' => 'short_text']],
]);

test('clean rollback restores legacy non-null unique user scope and cascade foreign keys', function () {
    DB::table('ai_account_settings')->insert(['user_id' => 1, 'account_id' => 1]);
    DB::table('ai_provider_credentials')->insert(['user_id' => 1, 'account_id' => 1, 'provider' => 'openrouter']);
    DB::table('ai_model_preferences')->insert(['user_id' => 1, 'account_id' => 1, 'purpose' => 'short_text']);

    accountScopeTransitionMigration()->down();

    foreach ([
        'ai_account_settings' => ['ai_account_settings_user_id_unique', 'ai_account_settings_account_id_unique'],
        'ai_provider_credentials' => ['ai_provider_credentials_user_id_provider_unique', 'ai_provider_credentials_account_id_provider_unique'],
        'ai_model_preferences' => ['ai_model_preferences_user_id_purpose_unique', 'ai_model_preferences_account_id_purpose_unique'],
    ] as $tableName => [$legacyUniqueIndex, $accountUniqueIndex]) {
        $userColumn = collect(Schema::getColumns($tableName))->firstWhere('name', 'user_id');
        $indexNames = collect(Schema::getIndexes($tableName))->pluck('name')->all();
        $userForeignKey = collect(Schema::getForeignKeys($tableName))
            ->first(fn (array $foreignKey): bool => $foreignKey['columns'] === ['user_id']);

        expect($userColumn['nullable'])->toBeFalse()
            ->and($indexNames)->toContain($legacyUniqueIndex)
            ->and($indexNames)->toContain($accountUniqueIndex)
            ->and(strtolower($userForeignKey['on_delete']))->toBe('cascade');
    }
});
