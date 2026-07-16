<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('database.connections.workspace_erasure_rollback', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'workspace_erasure_rollback');
    DB::setDefaultConnection('workspace_erasure_rollback');
    DB::purge('workspace_erasure_rollback');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->timestamp('account_erasure_started_at')->nullable();
    });
    Schema::create('accounts', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('photo_storage_cleanups', function (Blueprint $table): void {
        $table->id();
        $table->string('disk');
        $table->string('path', 1024);
        $table->char('path_hash', 64);
        $table->timestamp('completed_at')->nullable();
    });

    creatorSafeUserErasureProgressMigration()->up();
    creatorSafeUserErasureTargetsMigration()->up();
    creatorSafeUserErasureCleanupMigration()->up();

    workspaceErasureMarkerMigration()->up();
    workspaceErasureProgressMigration()->up();
    workspaceErasureObjectsMigration()->up();

    DB::table('users')->insert(['id' => 1]);
    DB::table('accounts')->insert(['id' => 1, 'name' => 'Safe workspace']);
});

afterEach(function () {
    DB::purge('workspace_erasure_rollback');
    config()->set('database.default', 'sqlite');
    DB::setDefaultConnection('sqlite');
});

function workspaceErasureMarkerMigration(): Migration
{
    return require database_path('migrations/2026_07_15_143431_add_erasure_started_at_to_accounts_table.php');
}

function creatorSafeUserErasureProgressMigration(): Migration
{
    return require database_path('migrations/2026_07_15_020533_create_account_erasure_progress_table.php');
}

function creatorSafeUserErasureTargetsMigration(): Migration
{
    return require database_path('migrations/2026_07_15_020533_create_account_erasure_targets_table.php');
}

function creatorSafeUserErasureCleanupMigration(): Migration
{
    return require database_path('migrations/2026_07_15_021602_create_account_erasure_cleanup_table.php');
}

function workspaceErasureProgressMigration(): Migration
{
    return require database_path('migrations/2026_07_15_143432_create_workspace_erasure_progress_table.php');
}

function workspaceErasureObjectsMigration(): Migration
{
    return require database_path('migrations/2026_07_15_143433_create_workspace_erasure_objects_table.php');
}

function workspaceErasureFirstReverseMigration(): Migration
{
    return require database_path('migrations/2026_07_15_143458_enforce_account_conversation_integrity.php');
}

/** @return array<string, mixed> */
function workspaceErasureRollbackSnapshot(): array
{
    return [
        'user_columns' => Schema::getColumns('users'),
        'user_progress_columns' => Schema::getColumns('account_erasure_progress'),
        'user_target_columns' => Schema::getColumns('account_erasure_targets'),
        'user_cleanup_columns' => Schema::getColumns('account_erasure_cleanup'),
        'account_columns' => Schema::getColumns('accounts'),
        'progress_columns' => Schema::getColumns('workspace_erasure_progress'),
        'object_columns' => Schema::getColumns('workspace_erasure_objects'),
        'users' => DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'user_progress' => DB::table('account_erasure_progress')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'user_targets' => DB::table('account_erasure_targets')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'user_cleanup' => DB::table('account_erasure_cleanup')->orderBy('account_erasure_progress_id')->get()->map(fn ($row) => (array) $row)->all(),
        'photo_cleanups' => DB::table('photo_storage_cleanups')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'accounts' => DB::table('accounts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'progress' => DB::table('workspace_erasure_progress')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'objects' => DB::table('workspace_erasure_objects')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
    ];
}

function insertActiveCreatorSafeUserErasure(string $phase): void
{
    DB::table('users')->where('id', 1)->update(['account_erasure_started_at' => now()]);
    $progressId = DB::table('account_erasure_progress')->insertGetId([
        'user_id' => 1,
        'phase' => $phase,
        'cursor' => 0,
        'revision' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('account_erasure_targets')->insert([
        'user_id' => 1,
        'resource_type' => 'account',
        'resource_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $cleanupId = DB::table('photo_storage_cleanups')->insertGetId([
        'disk' => 'private',
        'path' => "users/1/{$phase}.jpg",
        'path_hash' => hash('sha256', "users/1/{$phase}.jpg"),
        'completed_at' => null,
    ]);
    DB::table('account_erasure_cleanup')->insert([
        'account_erasure_progress_id' => $progressId,
        'photo_storage_cleanup_id' => $cleanupId,
    ]);
}

function insertWorkspaceErasureProgress(bool $completed, bool $storageVerified): int
{
    return DB::table('workspace_erasure_progress')->insertGetId([
        'account_id' => 1,
        'requested_by_user_id' => 1,
        'phase' => $completed ? 'completed' : 'drain_work',
        'checkpoint' => 'primary',
        'cursor' => 0,
        'revision' => 0,
        'attempts' => 0,
        'storage_verified_at' => $storageVerified ? now() : null,
        'completed_at' => $completed ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('rollback rejects an active account marker before changing schema or data', function () {
    DB::table('accounts')->where('id', 1)->update(['erasure_started_at' => now()]);
    $before = workspaceErasureRollbackSnapshot();

    expect(fn () => workspaceErasureFirstReverseMigration()->down())
        ->toThrow(RuntimeException::class, 'account erasure marker is active')
        ->and(workspaceErasureRollbackSnapshot())->toEqual($before);
});

test('rollback rejects creator-safe user erasure before workspace handoff without changing schema or proof', function (string $phase) {
    insertActiveCreatorSafeUserErasure($phase);
    $before = workspaceErasureRollbackSnapshot();

    expect(DB::table('workspace_erasure_progress')->exists())->toBeFalse()
        ->and(fn () => workspaceErasureFirstReverseMigration()->down())
        ->toThrow(RuntimeException::class, 'creator-safe user erasure is active')
        ->and(workspaceErasureRollbackSnapshot())->toEqual($before);
})->with(['scan_batches', 'finish']);

test('rollback rejects incomplete progress before changing schema or data', function () {
    insertWorkspaceErasureProgress(completed: false, storageVerified: false);
    $before = workspaceErasureRollbackSnapshot();

    expect(fn () => workspaceErasureObjectsMigration()->down())
        ->toThrow(RuntimeException::class, 'progress is incomplete or storage is unverified')
        ->and(workspaceErasureRollbackSnapshot())->toEqual($before);
});

test('rollback rejects an active manifest without completed deletion proof', function () {
    $progressId = insertWorkspaceErasureProgress(completed: true, storageVerified: true);
    $cleanupId = DB::table('photo_storage_cleanups')->insertGetId([
        'disk' => 'private',
        'path' => 'workspace/1/orphan.jpg',
        'path_hash' => hash('sha256', 'workspace/1/orphan.jpg'),
        'completed_at' => null,
    ]);
    DB::table('workspace_erasure_objects')->insert([
        'workspace_erasure_progress_id' => $progressId,
        'photo_storage_cleanup_id' => $cleanupId,
        'disk' => 'private',
        'path' => 'workspace/1/orphan.jpg',
        'path_hash' => hash('sha256', 'workspace/1/orphan.jpg'),
        'source_type' => 'storage_scan',
        'source_id' => 'orphan',
        'verified_missing_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = workspaceErasureRollbackSnapshot();

    expect(fn () => workspaceErasureObjectsMigration()->down())
        ->toThrow(RuntimeException::class, 'manifest deletion proof is incomplete')
        ->and(workspaceErasureRollbackSnapshot())->toEqual($before);
});

test('rollback discards completed verified workspace proof after completed user erasure artifacts cascade away', function () {
    DB::table('users')->insert(['id' => 2, 'account_erasure_started_at' => now()]);
    $userProgressId = DB::table('account_erasure_progress')->insertGetId([
        'user_id' => 2,
        'phase' => 'finish',
        'cursor' => 0,
        'revision' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('account_erasure_targets')->insert([
        'user_id' => 2,
        'resource_type' => 'account',
        'resource_id' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $userCleanupId = DB::table('photo_storage_cleanups')->insertGetId([
        'disk' => 'private',
        'path' => 'users/2/deleted.jpg',
        'path_hash' => hash('sha256', 'users/2/deleted.jpg'),
        'completed_at' => now(),
    ]);
    DB::table('account_erasure_cleanup')->insert([
        'account_erasure_progress_id' => $userProgressId,
        'photo_storage_cleanup_id' => $userCleanupId,
    ]);
    DB::table('users')->where('id', 2)->delete();

    expect(DB::table('account_erasure_progress')->where('user_id', 2)->exists())->toBeFalse()
        ->and(DB::table('account_erasure_targets')->where('user_id', 2)->exists())->toBeFalse()
        ->and(DB::table('account_erasure_cleanup')->where('account_erasure_progress_id', $userProgressId)->exists())->toBeFalse();

    $progressId = insertWorkspaceErasureProgress(completed: true, storageVerified: true);
    $cleanupId = DB::table('photo_storage_cleanups')->insertGetId([
        'disk' => 'private',
        'path' => 'workspace/1/deleted.jpg',
        'path_hash' => hash('sha256', 'workspace/1/deleted.jpg'),
        'completed_at' => now(),
    ]);
    DB::table('workspace_erasure_objects')->insert([
        'workspace_erasure_progress_id' => $progressId,
        'photo_storage_cleanup_id' => $cleanupId,
        'disk' => 'private',
        'path' => 'workspace/1/deleted.jpg',
        'path_hash' => hash('sha256', 'workspace/1/deleted.jpg'),
        'source_type' => 'storage_scan',
        'source_id' => 'deleted',
        'verified_missing_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    workspaceErasureObjectsMigration()->down();
    workspaceErasureProgressMigration()->down();
    workspaceErasureMarkerMigration()->down();

    expect(Schema::hasTable('workspace_erasure_objects'))->toBeFalse()
        ->and(Schema::hasTable('workspace_erasure_progress'))->toBeFalse()
        ->and(Schema::hasColumn('accounts', 'erasure_started_at'))->toBeFalse()
        ->and(DB::table('photo_storage_cleanups')->where('id', $cleanupId)->value('completed_at'))->not->toBeNull();
});
