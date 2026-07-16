<?php

namespace App\Ai\Responses;

use Laravel\Ai\Responses\Data\Usage;

class CostAwareUsage extends Usage
{
    public function __construct(
        int $promptTokens = 0,
        int $completionTokens = 0,
        int $cacheWriteInputTokens = 0,
        int $cacheReadInputTokens = 0,
        int $reasoningTokens = 0,
        public ?float $costUsd = null,
    ) {
        parent::__construct(
            $promptTokens,
            $completionTokens,
            $cacheWriteInputTokens,
            $cacheReadInputTokens,
            $reasoningTokens,
        );
    }
}
