<?php

namespace App\Jobs;

use App\Enums\PhotoProcessingStatus;
use App\Images\PhotoDerivativeService;
use App\Models\Photo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class GeneratePhotoDerivatives implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public Photo $photo)
    {
        //
    }

    /**
     * Normalize the photo and render its web derivatives, recording a
     * readable error and leaving the photo retryable when processing fails.
     */
    public function handle(PhotoDerivativeService $service): void
    {
        $this->photo->update([
            'processing_status' => PhotoProcessingStatus::Processing,
            'processing_error' => null,
        ]);

        try {
            $service->process($this->photo);
        } catch (Throwable $e) {
            $this->photo->update([
                'processing_status' => PhotoProcessingStatus::Failed,
                'processing_error' => Str::limit($e->getMessage(), 1000),
            ]);

            throw $e;
        }

        $this->photo->update([
            'processing_status' => PhotoProcessingStatus::Ready,
            'processing_error' => null,
            'processed_at' => now(),
        ]);

        // Auto-captioning waits for the normalized LLM input so the vision
        // model reads a right-side-up, sanely sized image.
        if ($this->photo->isUploaded() && blank($this->photo->text)) {
            DescribeUploadedPhoto::dispatch($this->photo);
        }
    }
}
