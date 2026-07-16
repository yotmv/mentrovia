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
        Schema::table('photo_generation_slots', function (Blueprint $table) {
            $table->boolean('uses_byok')->default(false)->after('model');
            $table->string('actual_provider', 100)->nullable()->after('uses_byok');
            $table->string('actual_model', 191)->nullable()->after('actual_provider');
            $table->decimal('actual_cost_usd', 12, 6)->nullable()->after('actual_model');
            $table->string('actual_cost_source', 20)->nullable()->after('actual_cost_usd');
        });

        Schema::create('ai_account_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('paid_ai_enabled')->default(true);
            $table->boolean('hosted_ai_enabled')->default(true);
            $table->boolean('byok_enabled')->default(false);
            $table->decimal('monthly_usd_limit', 10, 4)->nullable();
            $table->decimal('per_operation_usd_limit', 10, 4)->nullable();
            $table->unsignedTinyInteger('max_concurrency')->default(1);
            $table->timestamps();
        });

        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->text('secret');
            $table->char('fingerprint', 64);
            $table->string('last_four', 4);
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('ai_model_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 32);
            $table->string('mode', 16)->default('auto');
            $table->json('model_ids')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'purpose']);
        });

        Schema::create('ai_operation_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->index();
            $table->unsignedBigInteger('account_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('event', 32);
            $table->string('purpose', 32)->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('model', 191)->nullable();
            $table->char('credential_fingerprint', 64)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unsignedInteger('request_bytes')->nullable();
            $table->char('output_hash', 64)->nullable();
            $table->unsignedInteger('output_bytes')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->string('exception_class', 191)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['account_id', 'occurred_at']);
        });

        $this->createAuditImmutabilityTriggers();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_operation_audits') && DB::table('ai_operation_audits')->exists()) {
            throw new RuntimeException('Refusing to erase the permanent AI operation audit ledger.');
        }

        $this->dropAuditImmutabilityTriggers();
        Schema::table('photo_generation_slots', function (Blueprint $table) {
            $table->dropColumn(['uses_byok', 'actual_provider', 'actual_model', 'actual_cost_usd', 'actual_cost_source']);
        });
        Schema::dropIfExists('ai_operation_audits');
        Schema::dropIfExists('ai_model_preferences');
        Schema::dropIfExists('ai_provider_credentials');
        Schema::dropIfExists('ai_account_settings');
    }

    private function createAuditImmutabilityTriggers(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER ai_operation_audits_no_update BEFORE UPDATE ON ai_operation_audits BEGIN SELECT RAISE(ABORT, 'AI audit records are immutable'); END");
            DB::unprepared("CREATE TRIGGER ai_operation_audits_no_delete BEFORE DELETE ON ai_operation_audits BEGIN SELECT RAISE(ABORT, 'AI audit records are permanent'); END");

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER ai_operation_audits_no_update BEFORE UPDATE ON ai_operation_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AI audit records are immutable'");
            DB::unprepared("CREATE TRIGGER ai_operation_audits_no_delete BEFORE DELETE ON ai_operation_audits FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'AI audit records are permanent'");
        }
    }

    private function dropAuditImmutabilityTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ai_operation_audits_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ai_operation_audits_no_delete');
    }
};
