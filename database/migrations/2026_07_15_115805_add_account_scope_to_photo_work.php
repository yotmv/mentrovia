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
        foreach (['photos', 'photo_generation_batches', 'photo_operation_leases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('account_id')->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(['photos', 'photo_generation_batches', 'photo_operation_leases']) as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('account_id'));
        }
    }
};
