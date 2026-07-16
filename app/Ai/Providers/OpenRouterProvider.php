<?php

namespace App\Ai\Providers;

use App\Ai\Gateway\OpenRouterImageGateway;
use App\Ai\Gateway\OpenRouterTextGateway;
use App\Services\Ai\ByokHttpFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Providers\OpenRouterProvider as BaseOpenRouterProvider;

class OpenRouterProvider extends BaseOpenRouterProvider
{
    /** @param array<string, mixed> $config */
    public function __construct(array $config, Dispatcher $events, private ?ByokHttpFactory $byokHttp = null)
    {
        parent::__construct($config, $events);
    }

    public function textGateway(): StepTextGateway
    {
        return $this->textGateway ??= new OpenRouterTextGateway($this->events, $this->byokHttp);
    }

    /**
     * Get the provider's image gateway. Text, audio, transcription, and
     * embedding gateways stay on the stock implementation; only image
     * generation is swapped for the cost-accounting variant.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new OpenRouterImageGateway($this->events, $this->byokHttp);
    }
}
