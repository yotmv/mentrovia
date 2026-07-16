<?php

namespace App\Ai\Images\Exceptions;

use Laravel\Ai\Exceptions\AiException;

class ImageGenerationRejectedException extends AiException
{
    public static function forProvider(string $provider): self
    {
        return new self("AI provider [{$provider}] rejected the image generation request because it did not pass validation or moderation.");
    }
}
