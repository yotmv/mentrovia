<?php

namespace App\Services\Ai;

use App\Ai\Providers\OpenRouterProvider;
use Illuminate\Events\Dispatcher;

final class ByokOpenRouterProviderFactory
{
    public function __construct(private ByokHttpFactory $http) {}

    public function make(#[\SensitiveParameter] string $apiKey): OpenRouterProvider
    {
        $config = (array) config('ai.providers.openrouter', []);
        $config['name'] = 'openrouter';
        $config['key'] = $apiKey;

        return new OpenRouterProvider($config, new Dispatcher, $this->http);
    }
}
