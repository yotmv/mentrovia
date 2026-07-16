<?php

namespace App\Console\Commands;

use App\Enums\PhotoKind;
use App\Enums\PhotoProcessingStatus;
use App\Models\Photo;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoStorageCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:prune-originals
    {--days= : Override the configured retention window in days}
    {--dry-run : List what would be pruned without deleting anything}')]
#[Description('Delete large uploaded originals past the retention window, keeping the normalized LLM input and web derivatives')]
class PrunePhotoOriginalsCommand extends Command
{
    /**
     * Execute the console command. Generated masters and derivatives are
     * always kept; only uploaded originals are pruned, and only after the
     * normalized LLM input exists to take over as the canonical file.
     */
    public function handle(
        PhotoGenerationLifecycle $lifecycle,
        PhotoStorageCleanupService $cleanupService,
    ): int {
        $days = (int) ($this->option('days') ?? config('photostudio.processing.original_retention_days', 30));
        $dryRun = (bool) $this->option('dry-run');

        $candidateQuery = Photo::query()
            ->where('kind', PhotoKind::Uploaded)
            ->where('processing_status', PhotoProcessingStatus::Ready)
            ->where('created_at', '<', now()->subDays($days));

        $found = 0;
        $pruned = 0;

        $candidateQuery->chunkById(100, function ($photos) use (
            $dryRun,
            $lifecycle,
            $cleanupService,
            &$found,
            &$pruned,
        ): void {
            foreach ($photos as $photo) {
                $llmInputPath = $photo->derivativePath('llm-input');

                if ($llmInputPath === null || $llmInputPath === $photo->path) {
                    continue;
                }

                $found++;

                if ($dryRun) {
                    $this->line("Would prune [{$photo->path}] (photo #{$photo->id}).");

                    continue;
                }

                $lease = $lifecycle->acquireForPhoto($photo, 'original-retention-prune');

                if ($lease === null) {
                    continue;
                }

                try {
                    $cleanupIds = $lifecycle->withUsableLease(
                        $lease,
                        function () use ($photo, $cleanupService): ?array {
                            $lockedPhoto = Photo::query()->lockForUpdate()->find($photo->id);
                            $llmInputPath = $lockedPhoto?->derivativePath('llm-input');

                            if ($lockedPhoto === null || $llmInputPath === null || $llmInputPath === $lockedPhoto->path) {
                                return null;
                            }

                            $cleanupIds = $cleanupService
                                ->recordMany($lockedPhoto->disk, [$lockedPhoto->path])
                                ->pluck('id')
                                ->all();

                            $lockedPhoto->update(['path' => $llmInputPath]);

                            return $cleanupIds;
                        },
                    );

                    if (! is_array($cleanupIds)) {
                        continue;
                    }

                    $cleanupService->deleteRecorded($cleanupIds);
                } finally {
                    $lifecycle->finish($lease);
                }

                $pruned++;
                $this->line("Pruned [photo #{$photo->id}]; the LLM input is now its canonical file.");
            }
        });

        if ($found === 0) {
            $this->info('No originals are due for pruning.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d original(s) past the %d-day retention window.',
            $dryRun ? 'Found' : 'Pruned',
            $dryRun ? $found : $pruned,
            $days,
        ));

        return self::SUCCESS;
    }
}
