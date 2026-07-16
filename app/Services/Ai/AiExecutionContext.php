<?php

namespace App\Services\Ai;

use App\Models\AiProviderCredential;

class AiExecutionContext
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $provider,
        public readonly string $model,
        public readonly ?AiProviderCredential $credential,
        public readonly ?float $estimatedCostUsd,
    ) {}

    public function usesByok(): bool
    {
        return $this->credential instanceof AiProviderCredential;
    }
}
