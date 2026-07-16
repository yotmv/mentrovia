<?php

namespace App\Ai\Gateway;

use App\Ai\Responses\CostAwareUsage;
use App\Services\Ai\ByokHttpFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\PendingRequest;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Usage;

class OpenRouterTextGateway extends OpenRouterGateway
{
    public function __construct(Dispatcher $events, private ?ByokHttpFactory $byokHttp = null)
    {
        parent::__construct($events);
    }

    /**
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     * @return array<string, mixed>
     */
    protected function buildStepBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        return [
            ...parent::buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext),
            'usage' => ['include' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractUsage(array $data): Usage
    {
        $usage = (array) ($data['usage'] ?? []);
        $promptDetails = (array) ($usage['prompt_tokens_details'] ?? []);
        $completionDetails = (array) ($usage['completion_tokens_details'] ?? []);

        return new CostAwareUsage(
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            cacheWriteInputTokens: (int) ($promptDetails['cache_write_tokens'] ?? 0),
            cacheReadInputTokens: (int) ($promptDetails['cached_tokens'] ?? 0),
            reasoningTokens: (int) ($completionDetails['reasoning_tokens'] ?? 0),
            costUsd: isset($usage['cost']) ? (float) $usage['cost'] : null,
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
