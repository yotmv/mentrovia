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
        Schema::create('roadmap_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('fingerprint', 64);
            $table->unsignedBigInteger('revision')->default(1);
            $table->timestamp('last_synced_at');
            $table->timestamps();
        });

        Schema::create('roadmap_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_plan_id')->constrained()->cascadeOnDelete();
            $table->string('template_key');
            $table->string('phase', 40);
            $table->string('priority', 20);
            $table->string('title');
            $table->text('why_it_matters');
            $table->string('reviewer')->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->string('action_label')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->string('computed_profile_status', 32);
            $table->string('execution_status', 32);
            $table->boolean('is_active')->default(true);
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable();
            $table->foreignId('status_updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['roadmap_plan_id', 'template_key'], 'roadmap_items_plan_template_unique');
            $table->unique(['roadmap_plan_id', 'id'], 'roadmap_items_plan_id_unique');
            $table->index(
                ['roadmap_plan_id', 'is_active', 'execution_status', 'due_on'],
                'roadmap_items_plan_active_status_due_index',
            );
            $table->index(['roadmap_plan_id', 'assigned_user_id'], 'roadmap_items_plan_assignee_index');
            $table->index(['roadmap_plan_id', 'sort_order'], 'roadmap_items_plan_order_index');
        });

        Schema::create('roadmap_item_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('roadmap_plan_item_id');
            $table->unsignedBigInteger('depends_on_roadmap_plan_item_id');
            $table->timestamps();

            $table->foreign(
                ['roadmap_plan_id', 'roadmap_plan_item_id'],
                'roadmap_dependencies_item_plan_foreign',
            )->references(['roadmap_plan_id', 'id'])->on('roadmap_plan_items')->cascadeOnDelete();
            $table->foreign(
                ['roadmap_plan_id', 'depends_on_roadmap_plan_item_id'],
                'roadmap_dependencies_parent_plan_foreign',
            )->references(['roadmap_plan_id', 'id'])->on('roadmap_plan_items')->cascadeOnDelete();

            $table->unique(
                ['roadmap_plan_id', 'roadmap_plan_item_id', 'depends_on_roadmap_plan_item_id'],
                'roadmap_item_dependency_unique',
            );
        });

        Schema::create('roadmap_item_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_plan_item_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('reference_url', 2048)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['roadmap_plan_item_id', 'created_at'], 'roadmap_evidence_item_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roadmap_item_evidence');
        Schema::dropIfExists('roadmap_item_dependencies');
        Schema::dropIfExists('roadmap_plan_items');
        Schema::dropIfExists('roadmap_plans');
    }
};
