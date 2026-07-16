<?php

namespace App\Ai\Gateway;

use App\Ai\Images\GeneratedImageResponseValidator;
use App\Ai\Responses\CostAwareImageResponse;
use App\Services\Ai\ByokHttpFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

class OpenRouterImageGateway extends OpenRouterGateway
{
    public function __construct(Dispatcher $events, private ?ByokHttpFactory $byokHttp = null)
    {
        parent::__construct($events);
    }

    /**
     * Generate an image, additionally requesting OpenRouter's usage
     * accounting so the actual billed USD cost rides along with the
     * response. Token-billed models (FLUX, GPT-image, Gemini) charge for
     * reference-image input tokens, so static per-image estimates can be
     * off by multiples — the accounted cost is the audit-grade number.
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
        assert($provider instanceof Provider);

        $imageOptions = $provider->defaultImageOptions($size, $quality);

        $imageConfig = array_filter([
            'aspect_ratio' => $imageOptions['aspect_ratio'] ?? null,
            'image_size' => $imageOptions['image_size'] ?? null,
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)
                ->post('chat/completions', array_filter([
                    'model' => $model,
                    'messages' => $this->buildImageMessages($prompt, $attachments),
                    'modalities' => ['image'],
                    'image_config' => $imageConfig ?: null,
                    'usage' => ['include' => true],
                ]))
        );

        $data = (array) $response->json();

        $message = (array) ($data['choices'][0]['message'] ?? []);

        $images = collect((array) ($message['images'] ?? []))->map(function (array $image) {
            $url = $image['image_url']['url'] ?? '';

            if (preg_match('/^data:(image\/[\w+.-]+);base64,(.+)$/', $url, $matches)) {
                return GeneratedImageResponseValidator::fromBase64($matches[2], $matches[1]);
            }

            return null;
        })->filter()->values();

        $usage = (array) ($data['usage'] ?? []);

        return new CostAwareImageResponse(
            $images,
            new Usage($usage['prompt_tokens'] ?? 0, $usage['completion_tokens'] ?? 0),
            new Meta($provider->name(), $data['model'] ?? $model),
            isset($usage['cost']) ? (float) $usage['cost'] : null,
        );
    }

    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        if ($this->byokHttp === null) {
            return parent::client($provider, $timeout);
        }

        $config = $provider->additionalConfiguration();

        return $this->byokHttp->baseUrl($this->baseUrl($provider))
            ->withToken($provider->providerCredentials()['key'])
            ->withHeaders(array_filter([
                'HTTP-Referer' => $config['http_referer'] ?? null,
                'X-OpenRouter-Title' => $config['x_title'] ?? null,
            ]))
            ->timeout($timeout ?? 60)
            ->throw();
    }
}
