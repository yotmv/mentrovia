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
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('jurisdiction')->default('TX');
            $table->string('category');
            $table->longText('body_markdown');
            $table->text('source_summary')->nullable();
            $table->string('risk_level');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('next_review_at')->nullable();
            $table->string('status')->default('published');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['jurisdiction', 'category']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_articles');
    }
};
