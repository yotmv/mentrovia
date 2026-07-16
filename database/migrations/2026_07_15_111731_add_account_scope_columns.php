<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_account_id')->nullable()->index();
        });

        foreach (['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->index();
            });
        }

        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(config('ai.conversations.tables.conversations', 'agent_conversations'), fn (Blueprint $table) => $table->dropColumn('account_id'));

        foreach (array_reverse(['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences']) as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('account_id'));
        }

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('current_account_id'));
    }
};
