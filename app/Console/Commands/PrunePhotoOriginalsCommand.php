<?php

namespace App\Console\Commands;

use App\Enums\PhotoKind;
use App\Enums\PhotoProcessingStatus;
use App\Models\Photo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('photostudio.processing.original_retention_days', 30));
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Photo::query()
            ->where('kind', PhotoKind::Uploaded)
            ->where('processing_status', PhotoProcessingStatus::Ready)
            ->where('created_at', '<', now()->subDays($days))
            ->get()
            ->filter(function (Photo $photo) {
                $llmInput = $photo->derivativePath('llm-input');

                return $llmInput !== null && $llmInput !== $photo->path;
            });

        if ($candidates->isEmpty()) {
            $this->info('No originals are due for pruning.');

            return self::SUCCESS;
        }

        foreach ($candidates as $photo) {
            if ($dryRun) {
                $this->line("Would prune [{$photo->path}] (photo #{$photo->id}).");

                continue;
            }

            Storage::disk($photo->disk)->delete($photo->path);

            $photo->update(['path' => $photo->derivativePath('llm-input')]);

            $this->line("Pruned [photo #{$photo->id}]; the LLM input is now its canonical file.");
        }

        $this->info(sprintf(
            '%s %d original(s) past the %d-day retention window.',
            $dryRun ? 'Found' : 'Pruned',
            $candidates->count(),
            $days,
        ));

        return self::SUCCESS;
    }
}
