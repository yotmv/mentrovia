<?php

namespace App\Jobs;

use App\Ai\Agents\PhotoDescriber;
use App\Enums\PhotoTextSource;
use App\Models\Photo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

class DescribeUploadedPhoto implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public Photo $photo)
    {
        //
    }

    /**
     * Auto-generate a description for an uploaded photo the user did not
     * caption, so every stored image has auditable text.
     */
    public function handle(): void
    {
        if (filled($this->photo->text)) {
            return;
        }

        $analysis = config('photostudio.analysis');

        try {
            $response = (new PhotoDescriber)->prompt(
                'Describe this photo.',
                attachments: [Image::fromStorage($this->photo->llmInputPath(), $this->photo->disk)],
                provider: $analysis['provider'],
                model: $analysis['model'],
                timeout: $analysis['timeout'] ?? 120,
            );

            if (! $response instanceof StructuredAgentResponse) {
                return;
            }

            $this->photo->update([
                'text' => $response['description'],
                'text_source' => PhotoTextSource::Auto,
            ]);
        } catch (Throwable $e) {
            Log::warning('Auto-description failed for uploaded photo.', [
                'photo_id' => $this->photo->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
