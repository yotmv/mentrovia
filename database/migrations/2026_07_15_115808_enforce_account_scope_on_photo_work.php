<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $problems = collect();

        foreach (['photos', 'photo_generation_batches', 'photo_operation_leases'] as $tableName) {
            $ids = DB::table($tableName)
                ->leftJoin('projects', 'projects.id', '=', $tableName.'.project_id')
                ->leftJoin('accounts', 'accounts.id', '=', $tableName.'.account_id')
                ->where(function ($query) use ($tableName): void {
                    $query->whereNull($tableName.'.account_id')
                        ->orWhereNull('projects.id')
                        ->orWhereNull('accounts.id')
                        ->orWhereColumn($tableName.'.account_id', '!=', 'projects.account_id');
                })
                ->limit(20)
                ->pluck($tableName.'.id')
                ->map(fn (int|string $id): string => (string) $id)
                ->all();

            $this->capture($problems, $tableName.' without a valid project account snapshot', $ids);
        }

        $mismatchedPhotoIds = DB::table('photos')
            ->join('photo_generation_batches', 'photo_generation_batches.id', '=', 'photos.photo_generation_batch_id')
            ->whereColumn('photos.account_id', '!=', 'photo_generation_batches.account_id')
            ->limit(20)
            ->pluck('photos.id')
            ->map(fn (int|string $id): string => (string) $id)
            ->all();
        $this->capture($problems, 'generated photos with a mismatched batch account snapshot', $mismatchedPhotoIds);

        if ($problems->isNotEmpty()) {
            throw new RuntimeException('Photo work account-scope preflight failed: '.$problems->implode('; '));
        }

        foreach (['photos', 'photo_generation_batches', 'photo_operation_leases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('account_id')->nullable(false)->change();
                $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(['photos', 'photo_generation_batches', 'photo_operation_leases']) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['account_id']);
                $table->unsignedBigInteger('account_id')->nullable()->change();
            });
        }
    }

    /**
     * @param  Collection<int, string>  $problems
     * @param  array<int, string>  $ids
     */
    private function capture(Collection $problems, string $label, array $ids): void
    {
        if ($ids !== []) {
            $problems->push($label.' ['.implode(',', $ids).']');
        }
    }
};
