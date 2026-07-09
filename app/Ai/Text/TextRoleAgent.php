<?php

namespace App\Ai\Text;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class TextRoleAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    public function __construct(
        protected string $instructions,
        protected array $providerOptions = [],
    ) {}

    public function instructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $providerName = $provider instanceof Lab ? $provider->value : $provider;

        return $providerName === 'openrouter' ? $this->providerOptions : [];
    }
}
