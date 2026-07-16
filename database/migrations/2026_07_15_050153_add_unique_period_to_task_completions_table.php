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
        $duplicates = DB::table('task_completions')
            ->select(['business_task_id', 'completed_for'])
            ->groupBy('business_task_id', 'completed_for')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('business_task_id')
            ->orderBy('completed_for')
            ->limit(20)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $periods = $duplicates
                ->map(fn (object $duplicate): string => $duplicate->business_task_id.':'.$duplicate->completed_for)
                ->implode(', ');

            throw new RuntimeException(sprintf(
                'Cannot enforce unique task completion periods. Reconcile duplicate task:period pairs [%s], then rerun the migration.',
                $periods,
            ));
        }

        Schema::table('task_completions', function (Blueprint $table) {
            $table->unique(
                ['business_task_id', 'completed_for'],
                'task_completions_task_period_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_completions', function (Blueprint $table) {
            $table->dropUnique('task_completions_task_period_unique');
        });
    }
};
