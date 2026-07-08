<?php

namespace App\Jobs;

use App\Ai\Images\ImageModelCatalog;
use App\Ai\Responses\CostAwareImageResponse;
use App\Enums\PhotoCostSource;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use RuntimeException;
use Throwable;

class GeneratePhotoWithModel implements ShouldQueue
{
    use Batchable;
    use Queueable;

    /**
     * Create a new job instance.
     *
     * @param  array{provider: string, model: string}|null  $fallback
     */
    public function __construct(
        public PhotoGenerationBatch $generationBatch,
        public string $provider,
        public string $model,
        public string $prompt,
        public PhotoMode $mode,
        public ?array $fallback = null,
    ) {
        //
    }

    /**
     * Generate one image with the assigned model, degrading to the fallback
     * model when the primary fails so the export group stays whole.
     */
    public function handle(ImageModelCatalog $catalog): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $this->generateWith($catalog, $this->provider, $this->model);
        } catch (Throwable $e) {
            Log::warning('Image generation failed for the selected model.', [
                'batch_id' => $this->generationBatch->id,
                'provider' => $this->provider,
                'model' => $this->model,
                'exception' => $e->getMessage(),
            ]);

            if ($this->fallback === null) {
                throw $e;
            }

            $this->generateWith($catalog, $this->fallback['provider'], $this->fallback['model']);
        }
    }

    /**
     * Generate and persist one photo with the given catalog model.
     */
    protected function generateWith(ImageModelCatalog $catalog, string $provider, string $model): void
    {
        $candidate = $catalog->find($provider, $model);

        $attachments = $this->generationBatch->inputPhotos()
            ->take(max(1, $candidate->maxReferenceImages()))
            ->map(fn (Photo $photo) => ImageFile::fromStorage($photo->llmInputPath(), $photo->disk))
            ->all();

        $response = Image::of($this->prompt)
            ->attachments($attachments)
            ->timeout(300)
            ->generate($provider, $model);

        // Prefer the provider's actual billed cost when the gateway reports
        // it; token-billed models charge for reference-image input tokens,
        // so static estimates can be off by multiples.
        [$costUsd, $costSource] = $response instanceof CostAwareImageResponse && $response->costUsd !== null
            ? [$response->costUsd, PhotoCostSource::Provider]
            : [$candidate->effectiveUsdPerImage(count($attachments)), PhotoCostSource::Estimate];

        $generated = $response->firstImage();

        $extension = match ($generated->mime()) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $disk = config('photostudio.disk');

        $path = $generated->storeAs(
            config('photostudio.generated_prefix').Str::uuid7(),
            'original.'.$extension,
            disk: $disk,
        );

        if ($path === false) {
            throw new RuntimeException('Failed to store the generated image.');
        }

        $photo = Photo::create([
            'project_id' => $this->generationBatch->project_id,
            'user_id' => $this->generationBatch->user_id,
            'photo_generation_batch_id' => $this->generationBatch->id,
            'kind' => PhotoKind::Generated,
            'disk' => $disk,
            'path' => $path,
            'processing_status' => PhotoProcessingStatus::Pending,
            'text' => $this->prompt,
            'text_source' => PhotoTextSource::Auto,
            'provider' => $provider,
            'model' => $model,
            'mode' => $this->mode,
            'cost_usd' => $costUsd,
            'cost_source' => $costSource,
        ]);

        GeneratePhotoDerivatives::dispatch($photo);
    }
}
