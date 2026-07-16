<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $maximum = max(1, min(100, (int) config('photostudio.max_batch_inputs', 12)));
        $driver = DB::connection()->getDriverName();

        $invalidIds = match ($driver) {
            'mysql', 'mariadb' => DB::table('photo_generation_batches')
                ->whereRaw("JSON_TYPE(input_photo_ids) <> 'ARRAY' OR JSON_LENGTH(input_photo_ids) > ?", [$maximum])
                ->orderBy('id')
                ->limit(20)
                ->pluck('id'),
            'sqlite' => DB::table('photo_generation_batches')
                ->whereRaw("json_type(input_photo_ids) <> 'array' OR json_array_length(input_photo_ids) > ?", [$maximum])
                ->orderBy('id')
                ->limit(20)
                ->pluck('id'),
            default => DB::table('photo_generation_batches')
                ->orderBy('id')
                ->pluck('id')
                ->filter(function (int $id) use ($maximum): bool {
                    $encoded = DB::table('photo_generation_batches')->where('id', $id)->value('input_photo_ids');
                    $decoded = is_string($encoded) ? json_decode($encoded, true, 8) : null;

                    return ! is_array($decoded) || count($decoded) > $maximum;
                })
                ->take(20)
                ->values(),
        };

        if ($invalidIds->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Photo generation batches [%s] exceed the configured %d-input bound or do not contain an array. Reconcile those batches to at most %d owned integer photo IDs before rerunning this migration.',
                $invalidIds->implode(', '),
                $maximum,
                $maximum,
            ));
        }

        $invalidTypeIds = [];

        DB::table('photo_generation_batches')
            ->select(['id', 'input_photo_ids'])
            ->orderBy('id')
            ->chunkById(200, function ($batches) use (&$invalidTypeIds): bool {
                foreach ($batches as $batch) {
                    $ids = json_decode((string) $batch->input_photo_ids, true, 8);

                    if (! is_array($ids)
                        || array_values($ids) !== $ids
                        || collect($ids)->contains(fn (mixed $id): bool => ! is_int($id) || $id < 1)
                        || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
                    ) {
                        $invalidTypeIds[] = (int) $batch->id;
                    }

                    if (count($invalidTypeIds) >= 20) {
                        return false;
                    }
                }

                return true;
            });

        if ($invalidTypeIds !== []) {
            throw new RuntimeException(sprintf(
                'Photo generation batches [%s] contain duplicate, non-integer, non-positive, or non-list input IDs. Reconcile those rows to distinct owned integer photo IDs before rerunning this migration.',
                implode(', ', $invalidTypeIds),
            ));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration only validates legacy data before bounded code is deployed.
    }
};
