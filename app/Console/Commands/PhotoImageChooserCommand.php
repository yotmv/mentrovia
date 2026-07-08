<?php

namespace App\Console\Commands;

use App\Ai\Images\Exceptions\NoUsableImageModelException;
use App\Ai\Images\ImageModelCandidate;
use App\Ai\Images\ImageModelChooser;
use App\Ai\Images\ImageRequirements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:image-chooser
    {--aspect-ratio= : Required aspect ratio (e.g. 3:2); non-square needs native model support}
    {--min-quality= : Minimum curated quality score (0-100)}
    {--max-usd= : Maximum USD per image}
    {--image-input : Require reference image support}
    {--editing : Require targeted-edit support}
    {--edit-task : Rank for an edit of an existing image (judges on edit_quality scores)}
    {--text-rendering : Require text rendering support}
    {--reference-images=0 : Number of reference images the task will attach (affects effective cost)}
    {--count=3 : Number of models to select}')]
#[Description('Print the ranked image model table and the best-value picks for the given requirements')]
class PhotoImageChooserCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImageModelChooser $chooser): int
    {
        $requirements = new ImageRequirements(
            aspectRatio: $this->option('aspect-ratio') ?: null,
            requiresImageInput: (bool) $this->option('image-input'),
            requiresEditing: (bool) $this->option('editing'),
            requiresTextRendering: (bool) $this->option('text-rendering'),
            minQuality: (int) ($this->option('min-quality') ?? config('photostudio.chooser.requirements.min_quality')),
            maxUsdPerImage: (float) ($this->option('max-usd') ?? config('photostudio.chooser.requirements.max_usd_per_image')),
            referenceImageCount: (int) $this->option('reference-images'),
            task: $this->option('edit-task') ? ImageRequirements::TASK_EDIT : ImageRequirements::TASK_GENERATE,
        );

        $ranked = $chooser->ranked($requirements);

        if ($ranked->isEmpty()) {
            $this->error('No profiled image model satisfies these requirements. Check provider API keys and thresholds.');

            return self::FAILURE;
        }

        $this->table(
            ['#', 'Provider', 'Model', 'Quality', '$/image', '$ effective', 'Rank', 'Recommended', 'Score'],
            $ranked->values()->map(fn (ImageModelCandidate $candidate, int $index) => [
                $index + 1,
                $candidate->provider,
                $candidate->model,
                $candidate->effectiveQuality ?? $candidate->quality(),
                number_format($candidate->usdPerImage(), 3),
                number_format($candidate->effectiveCost ?? $candidate->usdPerImage(), 3),
                $candidate->popularityRank() ?? '-',
                $candidate->isRecommended() ? 'yes' : 'no',
                number_format($candidate->score, 4),
            ])->all(),
        );

        try {
            $selected = $chooser->chooseMany($requirements, max(1, (int) $this->option('count')));
        } catch (NoUsableImageModelException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Selected (best value first, one per model family):');

        foreach ($selected as $index => $candidate) {
            $this->line(sprintf(
                '  %d. %s — quality %d, $%s/image effective',
                $index + 1,
                $candidate->choiceId(),
                $candidate->effectiveQuality ?? $candidate->quality(),
                number_format($candidate->effectiveCost ?? $candidate->usdPerImage(), 3),
            ));
        }

        return self::SUCCESS;
    }
}
