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
        Schema::create('photo_generation_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_generation_batch_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 100);
            $table->string('model', 191);
            $table->string('mode', 40);
            $table->uuid('operation_uuid')->unique();
            $table->string('status', 40)->default('pending');
            $table->uuid('execution_token')->nullable();
            $table->unsignedInteger('fence')->default(0);
            $table->timestamp('enqueued_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();
            $table->string('staged_disk')->nullable();
            $table->string('staging_prefix', 1024);
            $table->string('staged_path', 1024)->nullable();
            $table->foreignId('photo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['photo_generation_batch_id', 'provider', 'model'],
                'photo_generation_slots_batch_provider_model_unique',
            );
            $table->index(['status', 'enqueued_at'], 'photo_generation_slots_dispatch_index');
            $table->index(['status', 'claim_expires_at'], 'photo_generation_slots_claim_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_generation_slots');
    }
};
