<?php

namespace App\Ai\Gateway;

use App\Ai\Images\Exceptions\ImageGenerationRejectedException;
use App\Ai\Images\GeneratedImageResponseValidator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use RuntimeException;

class ReplicateImageGateway implements ImageGateway
{
    use HandlesFailoverErrors;

    /**
     * Generate an image via Replicate's official-model predictions API.
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
        $this->ensureOfficialModelSlug($model);

        $profile = config('photostudio.models.replicate', [])[$model] ?? [];

        $input = $this->buildInput($profile, $prompt, $attachments, $size);

        try {
            $data = $this->withErrorHandling($provider->name(), function () use ($provider, $model, $input, $timeout) {
                return $this->client($provider, $timeout ?? 120)
                    ->withHeaders(['Prefer' => 'wait=60'])
                    ->post("models/{$model}/predictions", ['input' => $input])
                    ->json();
            });
        } catch (RequestException $e) {
            $this->rejectIfInvalid($provider, $prompt, $e);

            throw $e;
        }

        $data = $this->waitForPrediction($provider, $data, $timeout ?? 120);

        if (($data['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException(sprintf(
                'Replicate prediction for [%s] finished with status [%s].',
                $model,
                $data['status'] ?? 'unknown',
            ));
        }

        return new ImageResponse(
            collect([$this->downloadOutput($data['output'] ?? null, $model)]),
            new Usage(0, 0),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Only official models (owner/model slugs) are allowed; version hashes
     * point at community forks with unvetted schemas and pricing.
     */
    protected function ensureOfficialModelSlug(string $model): void
    {
        if (! preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/i', $model)) {
            throw new InvalidArgumentException(
                "Replicate model [{$model}] must be an official [owner/model] slug without a version hash."
            );
        }
    }

    /**
     * Build the prediction input, mapping the image attachments onto the
     * model-specific input field declared in the catalog profile.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<Image>  $attachments
     * @return array<string, mixed>
     */
    protected function buildInput(array $profile, string $prompt, array $attachments, ?string $size): array
    {
        $input = ['prompt' => $prompt];

        $references = collect($attachments)
            ->take(max(1, (int) ($profile['max_reference_images'] ?? 1)))
            ->map(fn (Image $image) => $this->referenceUrl($image))
            ->values();

        if ($references->isNotEmpty()) {
            $field = $profile['input_schema']['image_input'] ?? 'image_input';

            $input[$field] = ($profile['input_schema']['image_input_type'] ?? 'array') === 'single'
                ? $references->first()
                : $references->all();
        }

        if ($size !== null && ($profile['supports_aspect_ratio'] ?? false)) {
            $input['aspect_ratio'] = $size;
        }

        if ($profile['supports_output_format'] ?? false) {
            $input['output_format'] = 'png';
        }

        return $input;
    }

    /**
     * Represent an attachment as an HTTPS URL or base64 data URL.
     */
    protected function referenceUrl(Image $image): string
    {
        if ($image instanceof RemoteImage) {
            return $image->url;
        }

        if ($image instanceof Base64Image || $image instanceof StoredImage) {
            return 'data:'.($image->mimeType() ?? 'image/png').';base64,'.base64_encode($image->content());
        }

        throw new InvalidArgumentException('Unsupported reference image type ['.$image::class.'] for Replicate.');
    }

    /**
     * Poll the prediction until it leaves the starting/processing states.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function waitForPrediction(ImageProvider $provider, array $data, int $timeout): array
    {
        $pollUrl = $data['urls']['get'] ?? null;
        $deadline = now()->addSeconds($timeout);

        while (in_array($data['status'] ?? null, ['starting', 'processing'], true)) {
            if (! is_string($pollUrl) || $pollUrl === '' || now()->greaterThan($deadline)) {
                throw new RuntimeException('Timed out waiting for the Replicate prediction to finish.');
            }

            $this->ensureTrustedProviderUrl($provider, $pollUrl);

            Sleep::for(1)->second();

            $data = $this->withErrorHandling($provider->name(), function () use ($provider, $pollUrl) {
                return $this->client($provider, 30)->get($pollUrl)->json();
            });
        }

        return $data;
    }

    /**
     * Fetch the generated image bytes from the prediction output.
     */
    protected function downloadOutput(mixed $output, string $model): GeneratedImage
    {
        $url = is_array($output) ? ($output[0] ?? null) : $output;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("Replicate prediction for [{$model}] returned no output image.");
        }

        $this->ensureTrustedOutputUrl($url);

        $response = Http::connectTimeout((int) config('photostudio.http.connect_timeout', 10))
            ->timeout(60)
            ->withOptions([
                'allow_redirects' => false,
                ...GeneratedImageResponseValidator::httpOptions(),
            ])
            ->get($url);

        return GeneratedImageResponseValidator::fromResponse($response);
    }

    protected function ensureTrustedProviderUrl(ImageProvider $provider, string $url): void
    {
        $config = $provider->additionalConfiguration();
        $providerUrl = rtrim($config['url'] ?? 'https://api.replicate.com/v1', '/');

        if (! $this->isSameHttpsOrigin($url, $providerUrl)) {
            throw new RuntimeException('Replicate returned an untrusted prediction status URL.');
        }
    }

    protected function ensureTrustedOutputUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowedHosts = config('photostudio.http.replicate_output_hosts', ['replicate.delivery']);
        $hasTrustedHost = false;

        foreach (is_array($allowedHosts) ? $allowedHosts : [] as $allowedHost) {
            if (! is_string($allowedHost)) {
                continue;
            }

            $allowedHost = strtolower(trim($allowedHost));

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                $hasTrustedHost = true;

                break;
            }
        }

        if (($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! $hasTrustedHost) {
            throw new RuntimeException('Replicate returned an untrusted output image URL.');
        }
    }

    protected function isSameHttpsOrigin(string $url, string $trustedUrl): bool
    {
        $parts = parse_url($url);
        $trustedParts = parse_url($trustedUrl);

        return ($parts['scheme'] ?? null) === 'https'
            && ($trustedParts['scheme'] ?? null) === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($trustedParts['host'] ?? ''))
            && ($parts['port'] ?? 443) === ($trustedParts['port'] ?? 443);
    }

    /**
     * Map validation / moderation failures to a typed exception without
     * retaining prompt or provider response content in application logs.
     */
    protected function rejectIfInvalid(ImageProvider $provider, string $prompt, RequestException $e): void
    {
        $status = $e->response->status();

        if (in_array($status, [400, 422], true)) {
            Log::warning('Replicate rejected an image generation request.', [
                'status' => $status,
                'prompt_sha256' => hash('sha256', $prompt),
                'prompt_bytes' => strlen($prompt),
            ]);

            throw ImageGenerationRejectedException::forProvider(
                $provider->name(),
            );
        }
    }

    /**
     * Get an HTTP client for the Replicate API.
     */
    protected function client(ImageProvider $provider, int $timeout): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $baseUrl = GeneratedImageResponseValidator::requireHttpsApiUrl(
            (string) ($config['url'] ?? 'https://api.replicate.com/v1'),
            'Replicate',
        );

        return Http::baseUrl($baseUrl)
            ->withToken($provider->providerCredentials()['key'])
            ->connectTimeout((int) config('photostudio.http.connect_timeout', 10))
            ->timeout($timeout)
            ->withOptions(['allow_redirects' => false])
            ->throw();
    }
}
