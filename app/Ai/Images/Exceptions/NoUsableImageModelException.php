<?php

namespace App\Ai\Images\Exceptions;

use RuntimeException;

class NoUsableImageModelException extends RuntimeException
{
    public static function forRequirements(): self
    {
        return new self('No profiled image model satisfies the given requirements. Check provider API keys and requirement thresholds.');
    }
}
