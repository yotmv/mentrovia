<?php

namespace App\Console\Commands;

use App\Services\PhotoWorkReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:reconcile-work {--limit=100 : Maximum rows of each work type to inspect}')]
#[Description('Enqueue bounded lifecycle work that has never been placed on its database queue')]
class ReconcilePhotoWorkCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PhotoWorkReconciler $reconciler): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $counts = $reconciler->reconcile($limit);

        $this->info(sprintf(
            'Enqueued %d cleanup(s), %d user erasure(s), %d workspace erasure(s), %d photo job(s), %d description job(s), %d batch job(s), and %d generation slot(s).',
            $counts['cleanups'],
            $counts['erasures'],
            $counts['workspace_erasures'],
            $counts['photos'],
            $counts['descriptions'],
            $counts['batches'],
            $counts['slots'],
        ));

        return self::SUCCESS;
    }
}
