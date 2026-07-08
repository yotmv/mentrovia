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
        Schema::create('recurring_task_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_article_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category');
            $table->string('frequency');
            $table->json('applies_to');
            $table->json('due_rule');
            $table->string('confidence');
            $table->boolean('requires_professional_review')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['frequency', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_task_templates');
    }
};
