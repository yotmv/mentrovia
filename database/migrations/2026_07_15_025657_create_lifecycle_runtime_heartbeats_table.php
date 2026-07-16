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
        Schema::create('lifecycle_runtime_heartbeats', function (Blueprint $table) {
            $table->string('name', 80)->primary();
            $table->timestamp('last_seen_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lifecycle_runtime_heartbeats');
    }
};
