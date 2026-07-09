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
        Schema::create('validation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->json('normalized_request');
            $table->json('context_snapshot')->nullable();
            $table->string('status')->default('pending');
            $table->string('aggregate_decision')->nullable();
            $table->string('final_model_role')->nullable();
            $table->string('final_provider')->nullable();
            $table->string('final_model')->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->json('flags')->nullable();
            $table->json('concerns')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['knowledge_article_id', 'status']);
            $table->index(['knowledge_article_id', 'aggregate_decision']);
            $table->index(['user_id', 'created_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('validation_runs');
    }
};
