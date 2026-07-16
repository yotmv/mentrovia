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
        Schema::table('photo_operation_leases', function (Blueprint $table): void {
            $table->dropIndex('photo_leases_owner_active_index');
            $table->dropIndex(['project_owner_id']);
            $table->dropColumn('project_owner_id');
            $table->foreign('initiating_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photo_operation_leases', function (Blueprint $table): void {
            $table->dropForeign(['initiating_user_id']);
            $table->dropForeign(['project_id']);
            $table->unsignedBigInteger('project_owner_id')->nullable()->index()->after('initiating_user_id');
            $table->index(['project_owner_id', 'finished_at', 'expires_at'], 'photo_leases_owner_active_index');
        });
    }
};
