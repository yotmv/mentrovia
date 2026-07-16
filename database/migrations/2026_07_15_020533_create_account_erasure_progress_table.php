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
        Schema::create('account_erasure_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phase', 40)->default('scan_batches');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('enqueued_at')->nullable()->index();
            $table->timestamps();

            $table->index(['phase', 'cursor']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_erasure_progress');
    }
};
