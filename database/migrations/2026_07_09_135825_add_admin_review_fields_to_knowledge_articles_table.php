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
        Schema::table('knowledge_articles', function (Blueprint $table) {
            $table->text('admin_review_notes')->nullable()->after('version');
            $table->timestamp('admin_reviewed_at')->nullable()->after('admin_review_notes');
            $table->timestamp('revalidation_requested_at')->nullable()->after('admin_reviewed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_articles', function (Blueprint $table) {
            $table->dropColumn([
                'admin_review_notes',
                'admin_reviewed_at',
                'revalidation_requested_at',
            ]);
        });
    }
};
