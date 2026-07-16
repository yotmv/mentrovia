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
        Schema::table('ai_operation_audits', function (Blueprint $table) {
            $table->json('changed_fields')->nullable()->after('cost_usd');
            $table->char('before_fingerprint', 64)->nullable()->after('changed_fields');
            $table->char('after_fingerprint', 64)->nullable()->after('before_fingerprint');
            $table->index(['account_id', 'event', 'occurred_at', 'id'], 'ai_audit_account_event_timeline');
            $table->index(['account_id', 'actor_user_id', 'occurred_at', 'id'], 'ai_audit_account_actor_timeline');
            $table->index(['account_id', 'purpose', 'occurred_at', 'id'], 'ai_audit_account_purpose_timeline');
            $table->index(['account_id', 'provider', 'occurred_at', 'id'], 'ai_audit_account_provider_timeline');
            $table->index(['account_id', 'operation_id'], 'ai_audit_account_operation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('ai_operation_audits')
            ->whereNotNull('changed_fields')
            ->orWhereNotNull('before_fingerprint')
            ->orWhereNotNull('after_fingerprint')
            ->exists()) {
            throw new RuntimeException('Refusing to erase permanent AI trust center audit metadata.');
        }

        Schema::table('ai_operation_audits', function (Blueprint $table) {
            $table->dropIndex('ai_audit_account_event_timeline');
            $table->dropIndex('ai_audit_account_actor_timeline');
            $table->dropIndex('ai_audit_account_purpose_timeline');
            $table->dropIndex('ai_audit_account_provider_timeline');
            $table->dropIndex('ai_audit_account_operation');
            $table->dropColumn(['changed_fields', 'before_fingerprint', 'after_fingerprint']);
        });
    }
};
