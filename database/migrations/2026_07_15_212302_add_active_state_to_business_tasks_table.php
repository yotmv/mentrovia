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
        Schema::table('business_tasks', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('requires_professional_review');
            $table->timestamp('retired_at')->nullable()->after('is_active');
            $table->index(['business_id', 'is_active', 'due_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_tasks', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'is_active', 'due_on']);
            $table->dropColumn(['is_active', 'retired_at']);
        });
    }
};
