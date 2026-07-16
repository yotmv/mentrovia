<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $now = now();
                DB::table('accounts')->insertOrIgnore(['id' => $user->id, 'name' => $user->name.' workspace', 'created_at' => $user->created_at ?? $now, 'updated_at' => $now]);
                DB::table('account_user')->updateOrInsert(
                    ['account_id' => $user->id, 'user_id' => $user->id],
                    ['role' => 'owner', 'created_at' => $user->created_at ?? $now, 'updated_at' => $now],
                );
                DB::table('account_entitlements')->updateOrInsert(
                    ['account_id' => $user->id],
                    ['plan' => 'beta', 'status' => 'active', 'trial_ends_at' => null, 'created_at' => $user->created_at ?? $now, 'updated_at' => $now],
                );
                DB::table('users')->where('id', $user->id)->whereNull('current_account_id')->update(['current_account_id' => $user->id]);
            }
        }, 'id');

        foreach (['businesses', 'projects', 'ai_account_settings', 'ai_provider_credentials', 'ai_model_preferences'] as $tableName) {
            DB::table($tableName)->whereNull('account_id')->update(['account_id' => DB::raw('user_id')]);
        }

        DB::table(config('ai.conversations.tables.conversations', 'agent_conversations'))
            ->whereNull('account_id')->whereNotNull('user_id')->update(['account_id' => DB::raw('user_id')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This compatibility backfill is intentionally forward-only.
    }
};
