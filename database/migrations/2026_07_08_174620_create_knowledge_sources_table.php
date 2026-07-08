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
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_article_id')->constrained()->cascadeOnDelete();
            $table->string('source_name');
            $table->string('source_url', 2048);
            $table->string('source_type');
            $table->timestamp('retrieved_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
