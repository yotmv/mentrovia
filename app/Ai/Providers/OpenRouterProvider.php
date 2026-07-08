<?php

namespace App\Ai\Providers;

use App\Ai\Gateway\OpenRouterImageGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Providers\OpenRouterProvider as BaseOpenRouterProvider;

class OpenRouterProvider extends BaseOpenRouterProvider
{
    /**
     * Get the provider's image gateway. Text, audio, transcription, and
     * embedding gateways stay on the stock implementation; only image
     * generation is swapped for the cost-accounting variant.
     */
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new OpenRouterImageGateway($this->events);
    }
}
