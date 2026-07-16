<?php

namespace App\Services\Ai;

use App\Ai\Responses\CostAwareImageResponse;
use App\Ai\Responses\CostAwareUsage;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;

final class AiOperationResultMetadata
{
    public function __construct(
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?float $costUsd,
    ) {}

    public static function fromResponse(TextResponse|ImageResponse $response): self
    {
        $cost = $response instanceof CostAwareImageResponse ? $response->costUsd : null;

        if ($response instanceof TextResponse) {
            $stepCosts = $response->steps
                ->map(fn ($step): ?float => $step->usage instanceof CostAwareUsage ? $step->usage->costUsd : null)
                ->filter(fn (?float $value): bool => $value !== null);

            if ($stepCosts->isNotEmpty()) {
                $cost = (float) $stepCosts->sum();
            }
        }

        return new self(
            $response->meta->provider,
            $response->meta->model,
            $response->usage->promptTokens,
            $response->usage->completionTokens,
            $cost,
        );
    }
}
