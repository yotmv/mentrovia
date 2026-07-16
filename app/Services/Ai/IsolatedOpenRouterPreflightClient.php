<?php

namespace App\Services\Ai;

use App\Contracts\OpenRouterPreflightClient;
use App\Exceptions\OpenRouterPreflightFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class IsolatedOpenRouterPreflightClient implements OpenRouterPreflightClient
{
    private const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    private const OPENROUTER_KEY_URL = self::OPENROUTER_BASE_URL.'/key';

    private const OPENROUTER_MODELS_URL = self::OPENROUTER_BASE_URL.'/models?output_modalities=all';

    public function __construct(private ByokHttpFactory $http) {}

    /** @return array{key_valid: bool, label: string|null, models: array<string, array<int, string>>} */
    public function inspect(#[\SensitiveParameter] string $apiKey): array
    {
        $this->assertConfiguredEndpointSafe();
        $keyResponse = $this->get(self::OPENROUTER_KEY_URL, $apiKey);

        if (in_array($keyResponse->status(), [401, 403], true)) {
            return ['key_valid' => false, 'label' => null, 'models' => []];
        }

        if (! $keyResponse->successful()) {
            throw new OpenRouterPreflightFailed('key_endpoint_unavailable');
        }

        $keyPayload = $this->decode($keyResponse);
        $keyData = $keyPayload['data'] ?? null;

        if (! is_array($keyData)) {
            throw new OpenRouterPreflightFailed('invalid_key_response');
        }

        $label = $keyData['label'] ?? null;

        if ($label !== null && ! is_string($label)) {
            throw new OpenRouterPreflightFailed('invalid_key_response');
        }

        $modelsResponse = $this->get(self::OPENROUTER_MODELS_URL, $apiKey);

        if (! $modelsResponse->successful()) {
            throw new OpenRouterPreflightFailed('models_endpoint_unavailable');
        }

        return [
            'key_valid' => true,
            'label' => is_string($label) ? $this->sanitizeLabel($label) : null,
            'models' => $this->models($this->decode($modelsResponse)),
        ];
    }

    private function get(string $url, #[\SensitiveParameter] string $apiKey): Response
    {
        $attempts = max(1, (int) config('account-ai.openrouter_preflight.attempts', 3));
        $delays = array_values(config('account-ai.openrouter_preflight.retry_delays_ms', [100, 300]));

        try {
            return $this->http
                ->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout((float) config('account-ai.openrouter_preflight.connect_timeout_seconds', 3))
                ->timeout((float) config('account-ai.openrouter_preflight.timeout_seconds', 8))
                ->withOptions(['allow_redirects' => false])
                ->retry(
                    $attempts,
                    fn (int $attempt): int => (int) ($delays[min($attempt - 1, count($delays) - 1)] ?? 0),
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError()),
                    throw: false,
                )
                ->get($url);
        } catch (Throwable) {
            throw new OpenRouterPreflightFailed('provider_unavailable');
        }
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $maximumBytes = (int) config('account-ai.openrouter_preflight.max_response_bytes', 2_000_000);
        $contentLength = $response->header('Content-Length');

        if (is_numeric($contentLength) && (int) $contentLength > $maximumBytes) {
            throw new OpenRouterPreflightFailed('response_too_large');
        }

        $body = $response->body();

        if (strlen($body) > $maximumBytes) {
            throw new OpenRouterPreflightFailed('response_too_large');
        }

        try {
            $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OpenRouterPreflightFailed('invalid_json');
        }

        if (! is_array($payload)) {
            throw new OpenRouterPreflightFailed('invalid_json');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, array<int, string>>
     */
    private function models(array $payload): array
    {
        $data = $payload['data'] ?? null;
        $maximumModels = (int) config('account-ai.openrouter_preflight.max_models', 5_000);

        if (! is_array($data) || ! array_is_list($data) || count($data) > $maximumModels) {
            throw new OpenRouterPreflightFailed('invalid_models_response');
        }

        $models = [];

        foreach ($data as $model) {
            if (! is_array($model)
                || ! is_string($model['id'] ?? null)
                || strlen($model['id']) > 191
                || preg_match(config('account-ai.model_id_pattern'), $model['id']) !== 1) {
                throw new OpenRouterPreflightFailed('invalid_models_response');
            }

            $modalities = $model['architecture']['output_modalities'] ?? null;

            if (! is_array($modalities)
                || ! array_is_list($modalities)
                || array_filter($modalities, fn (mixed $modality): bool => ! is_string($modality) || strlen($modality) > 32) !== []) {
                throw new OpenRouterPreflightFailed('invalid_models_response');
            }

            $models[$model['id']] = array_values(array_unique($modalities));
        }

        return $models;
    }

    public function assertConfiguredEndpointSafe(): void
    {
        $baseUrl = (string) config('account-ai.openrouter_preflight.base_url');

        if ($baseUrl !== self::OPENROUTER_BASE_URL) {
            throw new OpenRouterPreflightFailed('unsafe_endpoint');
        }
    }

    private function sanitizeLabel(string $label): ?string
    {
        $label = preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($label)) ?? '';
        $label = trim(Str::limit($label, (int) config('account-ai.openrouter_preflight.label_max_length', 100), ''));

        return $label !== '' ? $label : null;
    }
}
