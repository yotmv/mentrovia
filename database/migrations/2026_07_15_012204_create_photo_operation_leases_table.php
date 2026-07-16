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
        Schema::create('photo_operation_leases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('initiating_user_id')->index();
            $table->unsignedBigInteger('project_owner_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->json('protected_user_ids');
            $table->string('purpose', 80);
            $table->timestamp('expires_at')->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['initiating_user_id', 'finished_at', 'expires_at'], 'photo_leases_initiator_active_index');
            $table->index(['project_owner_id', 'finished_at', 'expires_at'], 'photo_leases_owner_active_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_operation_leases');
    }
};
