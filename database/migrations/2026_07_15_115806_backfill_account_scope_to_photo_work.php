<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['photos', 'photo_generation_batches', 'photo_operation_leases'] as $tableName) {
            DB::table($tableName)
                ->whereNull('account_id')
                ->orderBy('id')
                ->chunkById(200, function (Collection $rows) use ($tableName): void {
                    $accountIds = DB::table('projects')
                        ->whereIn('id', $rows->pluck('project_id')->unique())
                        ->pluck('account_id', 'id');

                    foreach ($rows as $row) {
                        $accountId = $accountIds->get($row->project_id);

                        if ($accountId !== null) {
                            DB::table($tableName)
                                ->where('id', $row->id)
                                ->whereNull('account_id')
                                ->update(['account_id' => $accountId]);
                        }
                    }
                }, column: 'id');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This origin-account backfill is intentionally forward-only.
    }
};
