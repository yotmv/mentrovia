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
        Schema::table('account_erasure_cleanup', function (Blueprint $table) {
            $table->dropForeign(['photo_storage_cleanup_id']);
            $table->foreign('photo_storage_cleanup_id')
                ->references('id')
                ->on('photo_storage_cleanups')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_erasure_cleanup', function (Blueprint $table) {
            $table->dropForeign(['photo_storage_cleanup_id']);
            $table->foreign('photo_storage_cleanup_id')
                ->references('id')
                ->on('photo_storage_cleanups')
                ->cascadeOnDelete();
        });
    }
};
