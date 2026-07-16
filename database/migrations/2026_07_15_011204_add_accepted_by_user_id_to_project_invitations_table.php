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
        Schema::table('project_invitations', function (Blueprint $table) {
            $table->foreignId('accepted_by_user_id')
                ->nullable()
                ->after('invited_by_user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('accepted_at');
            $table->index('revoked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_invitations', function (Blueprint $table) {
            $table->dropIndex(['accepted_at']);
            $table->dropIndex(['revoked_at']);
            $table->dropConstrainedForeignId('accepted_by_user_id');
        });
    }
};
