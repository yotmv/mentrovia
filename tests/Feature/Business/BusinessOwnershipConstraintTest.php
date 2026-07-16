<?php

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

test('a user cannot own more than one business', function () {
    $user = User::factory()->create();

    Business::factory()->for($user)->create();

    expect(fn () => Business::factory()->for($user)->create())
        ->toThrow(QueryException::class);
});

test('the ownership migration refuses to choose between duplicate businesses', function () {
    $originalConnection = DB::getDefaultConnection();
    $isolatedConnection = 'business_migration_preflight';

    config([
        "database.connections.{$isolatedConnection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge($isolatedConnection);
    DB::setDefaultConnection($isolatedConnection);
    DB::connection()->getSchemaBuilder()->create('businesses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
    });
    DB::table('businesses')->insert([
        ['user_id' => 42],
        ['user_id' => 42],
    ]);

    $migration = require database_path('migrations/2026_07_14_233700_add_unique_user_id_index_to_businesses_table.php');

    try {
        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'duplicate businesses for user IDs [42]');
    } finally {
        DB::disconnect($isolatedConnection);
        DB::purge($isolatedConnection);
        DB::setDefaultConnection($originalConnection);
    }
});
