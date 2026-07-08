<?php

namespace App\Ai\Gateway;

use App\Ai\Images\Exceptions\ImageGenerationRejectedException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class StabilityImageGateway implements ImageGateway
{
    use HandlesFailoverErrors;

    /**
     * The strength applied to reference images; balances staying faithful
     * to the input photo against following the cleanup prompt.
     */
    protected const float IMAGE_STRENGTH = 0.6;

    /**
     * Generate an image via Stability's v2beta stable-image endpoints. The
     * model is the endpoint segment: core, ultra, or sd3.
     *
     * @param  array<Image>  $attachments
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        $profile = config('photostudio.models.stability', [])[$model] ?? [];

        $request = $this->client($provider, $timeout ?? 120)->asMultipart();

        $reference = $this->firstUsableReference($profile, $attachments);

        if ($reference !== null) {
            $request->attach('image', $reference->content(), 'reference.'.$this->referenceExtension($reference));
        }

        $fields = $this->buildFields($profile, $model, $prompt, $attachments, $size);

        try {
            $response = $this->withErrorHandling($provider->name(), function () use ($request, $model, $fields) {
                return $request->post("stable-image/generate/{$model}", $fields);
            });
        } catch (RequestException $e) {
            $this->rejectIfInvalid($provider, $prompt, $e);

            throw $e;
        }

        return new ImageResponse(
            collect([new GeneratedImage(
                base64_encode($response->body()),
                explode(';', $response->header('Content-Type') ?: 'image/png')[0],
            )]),
            new Usage(0, 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Build the multipart form fields for the request.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<Image>  $attachments
     * @return array<string, string>
     */
    protected function buildFields(array $profile, string $model, string $prompt, array $attachments, ?string $size): array
    {
        $fields = [
            'prompt' => $prompt,
            'output_format' => 'png',
        ];

        $hasReference = $this->firstUsableReference($profile, $attachments) !== null;

        if ($hasReference) {
            $fields['strength'] = (string) self::IMAGE_STRENGTH;

            if ($model === 'sd3') {
                $fields['mode'] = 'image-to-image';
            }
        } elseif ($size !== null && ($profile['supports_aspect_ratio'] ?? false)) {
            $fields['aspect_ratio'] = $size;
        }

        return $fields;
    }

    /**
     * Stability accepts a single reference image, and only on endpoints
     * profiled with image input support.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<Image>  $attachments
     */
    protected function firstUsableReference(array $profile, array $attachments): Base64Image|StoredImage|null
    {
        if (! ($profile['supports_image_input'] ?? false)) {
            return null;
        }

        foreach ($attachments as $attachment) {
            if ($attachment instanceof Base64Image || $attachment instanceof StoredImage) {
                return $attachment;
            }
        }

        return null;
    }

    protected function referenceExtension(Base64Image|StoredImage $image): string
    {
        return match ($image->mimeType()) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };
    }

    /**
     * Map validation / moderation failures to a typed exception, logging
     * the prompt so rejected generations can be audited.
     */
    protected function rejectIfInvalid(ImageProvider $provider, string $prompt, RequestException $e): void
    {
        $status = $e->response->status();

        if (in_array($status, [400, 403, 422], true)) {
            Log::warning('Stability rejected an image generation request.', [
                'status' => $status,
                'prompt' => $prompt,
                'detail' => $e->response->json('errors', $e->response->body()),
            ]);

            throw ImageGenerationRejectedException::forProvider(
                $provider->name(),
                (string) json_encode($e->response->json('errors', ['validation failed'])),
                $e,
            );
        }
    }

    /**
     * Get an HTTP client for the Stability API.
     */
    protected function client(ImageProvider $provider, int $timeout): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        return Http::baseUrl(rtrim($config['url'] ?? 'https://api.stability.ai/v2beta', '/'))
            ->withToken($provider->providerCredentials()['key'])
            ->withHeaders(['Accept' => 'image/*'])
            ->timeout($timeout)
            ->throw();
    }
}
