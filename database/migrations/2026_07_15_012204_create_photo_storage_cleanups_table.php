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
        Schema::create('photo_storage_cleanups', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('path', 1024);
            $table->char('path_hash', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'path_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_storage_cleanups');
    }
};
