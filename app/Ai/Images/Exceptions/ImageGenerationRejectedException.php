<?php

namespace App\Ai\Images\Exceptions;

use Laravel\Ai\Exceptions\AiException;
use Throwable;

class ImageGenerationRejectedException extends AiException
{
    public static function forProvider(string $provider, string $detail, ?Throwable $previous = null): self
    {
        return new self(
            "AI provider [{$provider}] rejected the image generation request (moderation or validation): {$detail}",
            0,
            $previous,
        );
    }
}
