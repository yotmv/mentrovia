<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspace_erasure_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->unique();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('phase', 40)->default('drain_work');
            $table->string('checkpoint', 32)->default('primary');
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('revision')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('dispatch_token')->nullable()->unique();
            $table->timestamp('enqueued_at')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable()->index();
            $table->timestamp('last_progress_at')->nullable()->index();
            $table->timestamp('storage_verified_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();

            $table->index(['phase', 'cursor']);
            $table->index(['completed_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->assertWorkspaceErasureRollbackIsSafe();

        Schema::dropIfExists('workspace_erasure_progress');
    }

    private function assertWorkspaceErasureRollbackIsSafe(): void
    {
        if ((Schema::hasColumn('users', 'account_erasure_started_at')
                && DB::table('users')->whereNotNull('account_erasure_started_at')->exists())
            || (Schema::hasTable('account_erasure_progress')
                && DB::table('account_erasure_progress')->exists())
            || (Schema::hasTable('account_erasure_targets')
                && DB::table('account_erasure_targets')->where('resource_type', 'account')->exists())) {
            throw new RuntimeException('Cannot roll back workspace erasure while creator-safe user erasure is active.');
        }

        if (Schema::hasColumn('accounts', 'erasure_started_at')
            && DB::table('accounts')->whereNotNull('erasure_started_at')->exists()) {
            throw new RuntimeException('Cannot roll back workspace erasure while an account erasure marker is active.');
        }

        if (Schema::hasTable('workspace_erasure_progress')
            && DB::table('workspace_erasure_progress')
                ->where(function ($query): void {
                    $query->whereNull('completed_at')->orWhereNull('storage_verified_at');
                })
                ->exists()) {
            throw new RuntimeException('Cannot roll back workspace erasure while progress is incomplete or storage is unverified.');
        }

        if (Schema::hasTable('workspace_erasure_objects')
            && DB::table('workspace_erasure_objects as object')
                ->leftJoin('workspace_erasure_progress as progress', 'progress.id', '=', 'object.workspace_erasure_progress_id')
                ->leftJoin('photo_storage_cleanups as cleanup', 'cleanup.id', '=', 'object.photo_storage_cleanup_id')
                ->where(function ($query): void {
                    $query->whereNull('object.verified_missing_at')
                        ->orWhereNull('progress.id')
                        ->orWhereNull('progress.completed_at')
                        ->orWhereNull('progress.storage_verified_at')
                        ->orWhereNull('cleanup.id')
                        ->orWhereNull('cleanup.completed_at');
                })
                ->exists()) {
            throw new RuntimeException('Cannot roll back workspace erasure while manifest deletion proof is incomplete.');
        }
    }
};
