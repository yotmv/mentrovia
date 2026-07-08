<?php

namespace App\Ai\Images\Exceptions;

use InvalidArgumentException;

class UnknownImageModelException extends InvalidArgumentException
{
    public static function for(string $provider, string $model): self
    {
        return new self("Image model [{$provider}::{$model}] is not profiled in the photostudio model catalog.");
    }
}
