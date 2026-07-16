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
        $duplicateUserIds = DB::table('businesses')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('user_id')
            ->limit(20)
            ->pluck('user_id');

        if ($duplicateUserIds->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Cannot enforce one business per user. Reconcile duplicate businesses for user IDs [%s], then rerun the migration.',
                $duplicateUserIds->implode(', '),
            ));
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
