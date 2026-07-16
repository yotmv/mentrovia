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
        Schema::create('account_erasure_cleanup', function (Blueprint $table) {
            $table->foreignId('account_erasure_progress_id')
                ->constrained('account_erasure_progress')
                ->cascadeOnDelete();
            $table->foreignId('photo_storage_cleanup_id')
                ->constrained('photo_storage_cleanups')
                ->cascadeOnDelete();

            $table->primary(
                ['account_erasure_progress_id', 'photo_storage_cleanup_id'],
                'account_erasure_cleanup_primary',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_erasure_cleanup');
    }
};
