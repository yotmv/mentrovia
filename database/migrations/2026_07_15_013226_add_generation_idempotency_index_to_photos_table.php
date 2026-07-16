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
        $duplicates = DB::table('photos')
            ->select(['photo_generation_batch_id', 'provider', 'model'])
            ->whereNotNull('photo_generation_batch_id')
            ->whereNotNull('provider')
            ->whereNotNull('model')
            ->groupBy(['photo_generation_batch_id', 'provider', 'model'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('photo_generation_batch_id')
            ->limit(20)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $slots = $duplicates
                ->map(fn (object $duplicate): string => sprintf(
                    '%s:%s:%s',
                    $duplicate->photo_generation_batch_id,
                    $duplicate->provider,
                    $duplicate->model,
                ))
                ->implode(', ');

            throw new RuntimeException(sprintf(
                'Cannot enforce one generated photo per model slot. Reconcile duplicate batch/provider/model slots [%s] by retaining one metadata row and durably deleting every duplicate object before rerunning the migration.',
                $slots,
            ));
        }

        Schema::table('photos', function (Blueprint $table) {
            $table->unique(
                ['photo_generation_batch_id', 'provider', 'model'],
                'photos_generation_model_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropUnique('photos_generation_model_unique');
        });
    }
};
