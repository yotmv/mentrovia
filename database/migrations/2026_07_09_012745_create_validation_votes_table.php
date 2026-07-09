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
        Schema::create('validation_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_run_id')->constrained()->cascadeOnDelete();
            $table->string('model_role');
            $table->string('provider');
            $table->string('model');
            $table->string('vote');
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('flags')->nullable();
            $table->json('concerns')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['validation_run_id', 'model_role']);
            $table->index(['provider', 'model']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_votes');
    }
};
