<?php

namespace App\Jobs;

use App\Ai\Agents\PhotoBatchAnalyst;
use App\Ai\Images\ImageModelCandidate;
use App\Ai\Images\ImageModelChooser;
use App\Ai\Images\ImageRequirements;
use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoMode;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

class RunPhotoGenerationBatch implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public PhotoGenerationBatch $generationBatch)
    {
        //
    }

    /**
     * Analyze the uploaded photos, select the top best-value models, and
     * fan out one generation job per selected model.
     */
    public function handle(ImageModelChooser $chooser): void
    {
        $batch = $this->generationBatch;

        $batch->update(['status' => GenerationBatchStatus::Processing]);

        try {
            $inputs = $batch->inputPhotos();

            if ($inputs->isEmpty()) {
                throw new RuntimeException('The generation batch has no input photos.');
            }

            $analysis = $this->analyze($batch, $inputs);

            $batch->update(['analysis' => $analysis]);

            $prompt = filled($analysis['group_prompt'] ?? null)
                ? $analysis['group_prompt']
                : (string) $batch->user_text;

            if (blank($prompt)) {
                throw new RuntimeException('The photo analysis did not produce a generation prompt and the batch has no user notes to fall back on.');
            }

            $mode = collect((array) ($analysis['images'] ?? []))->pluck('verdict')->contains(PhotoMode::Recreate->value)
                ? PhotoMode::Recreate
                : PhotoMode::Cleanup;

            $requirements = new ImageRequirements(
                requiresImageInput: true,
                requiresEditing: $mode === PhotoMode::Cleanup,
                minQuality: (int) config('photostudio.chooser.requirements.min_quality'),
                maxUsdPerImage: (float) config('photostudio.chooser.requirements.max_usd_per_image'),
                referenceImageCount: $inputs->count(),
            );

            $selected = $chooser->forConfiguredProvider(
                $requirements,
                (int) config('photostudio.results_per_batch', 3),
            );

            $fallbacks = $this->fallbacksFor($chooser, $requirements, $selected);

            $batch->update([
                'selected_models' => $selected->map(
                    fn (ImageModelCandidate $candidate) => $candidate->toDigest()
                )->all(),
            ]);

            $batchId = $batch->id;

            Bus::batch(
                $selected->values()->map(function (ImageModelCandidate $candidate, int $index) use ($batch, $fallbacks, $prompt, $mode) {
                    $fallback = $fallbacks->get($index);

                    return new GeneratePhotoWithModel(
                        $batch,
                        $candidate->provider,
                        $candidate->model,
                        $prompt,
                        $mode,
                        $fallback !== null
                            ? ['provider' => $fallback->provider, 'model' => $fallback->model]
                            : null,
                    );
                })->all()
            )
                ->allowFailures()
                ->finally(function (Batch $busBatch) use ($batchId) {
                    $batch = PhotoGenerationBatch::find($batchId);

                    $batch?->update($batch->generatedPhotos()->exists()
                        ? ['status' => GenerationBatchStatus::Completed]
                        : ['status' => GenerationBatchStatus::Failed, 'error' => 'Every selected model failed to generate an image.']);
                })
                ->name('photo-generation-batch-'.$batchId)
                ->dispatch();
        } catch (Throwable $e) {
            $batch->update([
                'status' => GenerationBatchStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Run the vision analysis over the input photos.
     *
     * Providers silently return an empty structured payload when the model
     * emits unparseable JSON, so retry once before giving up.
     *
     * @param  EloquentCollection<int, Photo>  $inputs
     * @return array<string, mixed>
     */
    protected function analyze(PhotoGenerationBatch $batch, EloquentCollection $inputs): array
    {
        $config = config('photostudio.analysis');

        $prompt = sprintf(
            "Analyze the %d attached photos.\n\nUser notes: %s",
            $inputs->count(),
            filled($batch->user_text) ? $batch->user_text : '(none provided)',
        );

        $attachments = $inputs->map(
            fn (Photo $photo) => Image::fromStorage($photo->llmInputPath(), $photo->disk)
        )->all();

        $analysis = [];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = (new PhotoBatchAnalyst)->prompt(
                $prompt,
                attachments: $attachments,
                provider: $config['provider'],
                model: $config['model'],
                timeout: $config['timeout'] ?? 120,
            );

            $analysis = $response instanceof StructuredAgentResponse ? $response->toArray() : [];

            if (filled($analysis['group_prompt'] ?? null)) {
                return $analysis;
            }

            Log::warning('Photo batch analysis returned no usable structured output.', [
                'batch_id' => $batch->id,
                'attempt' => $attempt,
                'response_text' => Str::limit($response->text, 500),
            ]);
        }

        return $analysis;
    }

    /**
     * Next-ranked candidates (excluding the selected ones) used as one
     * fallback per generation job, so a single model failure degrades to
     * a substitute instead of a smaller export group.
     *
     * @param  Collection<int, ImageModelCandidate>  $selected
     * @return Collection<int, ImageModelCandidate>
     */
    protected function fallbacksFor(
        ImageModelChooser $chooser,
        ImageRequirements $requirements,
        Collection $selected,
    ): Collection {
        if (config('photostudio.provider', 'auto') !== 'auto') {
            return collect();
        }

        $selectedIds = $selected->map(fn (ImageModelCandidate $candidate) => $candidate->choiceId());

        return $chooser->ranked($requirements)
            ->reject(fn (ImageModelCandidate $candidate) => $selectedIds->contains($candidate->choiceId()))
            ->take($selected->count())
            ->values();
    }
}
