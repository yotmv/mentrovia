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
        Schema::table('photo_generation_batches', function (Blueprint $table) {
            $table->string('analysis_state', 40)->default('pending')->after('analysis_enqueued_at');
            $table->uuid('analysis_operation_uuid')->nullable()->unique()->after('analysis_state');
            $table->uuid('analysis_execution_token')->nullable()->after('analysis_operation_uuid');
            $table->unsignedInteger('analysis_fence')->default(0)->after('analysis_execution_token');
            $table->timestamp('analysis_claim_expires_at')->nullable()->after('analysis_fence');
            $table->timestamp('analysis_provider_started_at')->nullable()->after('analysis_claim_expires_at');
            $table->string('analysis_failure_code', 80)->nullable()->after('analysis_provider_started_at');
            $table->index(['analysis_state', 'analysis_claim_expires_at'], 'photo_batches_analysis_claim_index');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->string('description_state', 40)->default('pending')->after('description_enqueued_at');
            $table->uuid('description_operation_uuid')->nullable()->unique()->after('description_state');
            $table->uuid('description_execution_token')->nullable()->after('description_operation_uuid');
            $table->unsignedInteger('description_fence')->default(0)->after('description_execution_token');
            $table->timestamp('description_claim_expires_at')->nullable()->after('description_fence');
            $table->timestamp('description_provider_started_at')->nullable()->after('description_claim_expires_at');
            $table->string('description_failure_code', 80)->nullable()->after('description_provider_started_at');
            $table->index(['description_state', 'description_claim_expires_at'], 'photos_description_claim_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex('photos_description_claim_index');
            $table->dropUnique(['description_operation_uuid']);
            $table->dropColumn([
                'description_state',
                'description_operation_uuid',
                'description_execution_token',
                'description_fence',
                'description_claim_expires_at',
                'description_provider_started_at',
                'description_failure_code',
            ]);
        });

        Schema::table('photo_generation_batches', function (Blueprint $table) {
            $table->dropIndex('photo_batches_analysis_claim_index');
            $table->dropUnique(['analysis_operation_uuid']);
            $table->dropColumn([
                'analysis_state',
                'analysis_operation_uuid',
                'analysis_execution_token',
                'analysis_fence',
                'analysis_claim_expires_at',
                'analysis_provider_started_at',
                'analysis_failure_code',
            ]);
        });
    }
};
