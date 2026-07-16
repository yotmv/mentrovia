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
        Schema::table('photos', function (Blueprint $table) {
            $table->timestamp('derivatives_enqueued_at')->nullable()->after('processing_status');
            $table->timestamp('description_enqueued_at')->nullable()->after('derivatives_enqueued_at');
            $table->index(['processing_status', 'derivatives_enqueued_at'], 'photos_derivatives_dispatch_index');
            $table->index(['kind', 'description_enqueued_at'], 'photos_description_dispatch_index');
        });

        Schema::table('photo_generation_batches', function (Blueprint $table) {
            $table->timestamp('analysis_enqueued_at')->nullable()->after('status');
            $table->index(['status', 'analysis_enqueued_at'], 'photo_batches_analysis_dispatch_index');
        });

        Schema::table('photo_storage_cleanups', function (Blueprint $table) {
            $table->timestamp('enqueued_at')->nullable()->after('last_attempted_at');
            $table->index(['completed_at', 'enqueued_at'], 'photo_cleanups_dispatch_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photo_storage_cleanups', function (Blueprint $table) {
            $table->dropIndex('photo_cleanups_dispatch_index');
            $table->dropColumn('enqueued_at');
        });

        Schema::table('photo_generation_batches', function (Blueprint $table) {
            $table->dropIndex('photo_batches_analysis_dispatch_index');
            $table->dropColumn('analysis_enqueued_at');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex('photos_derivatives_dispatch_index');
            $table->dropIndex('photos_description_dispatch_index');
            $table->dropColumn(['derivatives_enqueued_at', 'description_enqueued_at']);
        });
    }
};
