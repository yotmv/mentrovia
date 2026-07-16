<?php

namespace App\Services\Ai;

final readonly class OpenRouterPreflightResult
{
    /**
     * @param  array<int, array{purpose: string, model: string, exists: bool, compatible: bool, required_modality: string}>  $models
     */
    public function __construct(
        public string $operationId,
        public string $status,
        public ?bool $keyValid,
        public ?string $label,
        public array $models,
        public string $message,
    ) {}
}
