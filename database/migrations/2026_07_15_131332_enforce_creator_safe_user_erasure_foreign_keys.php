<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->indexExists('businesses', 'businesses_user_id_unique')) {
            Schema::table('businesses', function (Blueprint $table): void {
                $table->dropUnique('businesses_user_id_unique');
            });
        }

        foreach (['businesses', 'projects', 'brand_kits', 'advertising_kits'] as $tableName) {
            $this->makeCreatorNullable($tableName, 'user_id');
        }

        $this->makeCreatorNullable('project_invitations', 'invited_by_user_id');
        $this->makeCreatorNullable('account_invitations', 'invited_by_user_id');

        Schema::table('validation_runs', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });

        foreach (['photos', 'photo_generation_batches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }

        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        Schema::table($conversations, function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table($messages, function (Blueprint $table) use ($conversations): void {
            $table->foreign('conversation_id')->references('id')->on($conversations)->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['businesses', 'projects', 'brand_kits', 'advertising_kits'] as $tableName) {
            if (DB::table($tableName)->whereNull('user_id')->exists()) {
                throw new RuntimeException("Cannot restore required creator attribution for {$tableName}.");
            }
        }

        if (DB::table('project_invitations')->whereNull('invited_by_user_id')->exists()) {
            throw new RuntimeException('Cannot restore required project invitation attribution.');
        }

        if (DB::table('account_invitations')->whereNull('invited_by_user_id')->exists()) {
            throw new RuntimeException('Cannot restore required account invitation attribution.');
        }

        $duplicateBusinessCreatorIds = DB::table('businesses')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->pluck('user_id')
            ->all();

        if ($duplicateBusinessCreatorIds !== []) {
            throw new RuntimeException('Cannot restore unique business creator attribution ['.implode(',', $duplicateBusinessCreatorIds).'].');
        }

        $conversations = config('ai.conversations.tables.conversations', 'agent_conversations');
        $messages = config('ai.conversations.tables.messages', 'agent_conversation_messages');

        foreach (['conversation_id', 'user_id'] as $column) {
            if ($this->foreignKeyExists($messages, $column)) {
                Schema::table($messages, function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                });
            }
        }
        if ($this->foreignKeyExists($conversations, 'account_id')) {
            Schema::table($conversations, function (Blueprint $table): void {
                $table->dropForeign(['account_id']);
            });
        }
        if ($this->foreignKeyExists($conversations, 'user_id')) {
            Schema::table($conversations, function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
            });
        }
        Schema::table($conversations, function (Blueprint $table): void {
            $table->foreign('account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        foreach (['photos', 'photo_generation_batches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }

        Schema::table('validation_runs', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->nullOnDelete();
        });

        foreach (array_reverse(['businesses', 'projects', 'brand_kits', 'advertising_kits']) as $tableName) {
            $this->restoreRequiredCreator($tableName, 'user_id');
        }

        Schema::table('businesses', function (Blueprint $table): void {
            $table->unique('user_id');
        });

        $this->restoreRequiredCreator('project_invitations', 'invited_by_user_id');
        $this->restoreRequiredCreator('account_invitations', 'invited_by_user_id');
    }

    private function makeCreatorNullable(string $tableName, string $column): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropForeign([$column]);
            $table->unsignedBigInteger($column)->nullable()->change();
            $table->foreign($column)->references('id')->on('users')->nullOnDelete();
        });
    }

    private function restoreRequiredCreator(string $tableName, string $column): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($column): void {
            $table->dropForeign([$column]);
            $table->unsignedBigInteger($column)->nullable(false)->change();
            $table->foreign($column)->references('id')->on('users')->cascadeOnDelete();
        });
    }

    private function foreignKeyExists(string $tableName, string $column): bool
    {
        return collect(Schema::getForeignKeys($tableName))->contains(
            fn (array $foreignKey): bool => ($foreignKey['columns'] ?? null) === [$column],
        );
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $indexName,
        );
    }
};
