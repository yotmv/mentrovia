<?php

namespace App\Contracts;

interface OpenRouterPreflightClient
{
    public function assertConfiguredEndpointSafe(): void;

    /** @return array{key_valid: bool, label: string|null, models: array<string, array<int, string>>} */
    public function inspect(#[\SensitiveParameter] string $apiKey): array;
}
