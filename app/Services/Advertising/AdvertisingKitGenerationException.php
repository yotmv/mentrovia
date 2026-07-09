<?php

namespace App\Services\Advertising;

use RuntimeException;

class AdvertisingKitGenerationException extends RuntimeException
{
    public static function unstructuredResponse(): self
    {
        return new self('The ad copy model did not return a structured JSON advertising kit.');
    }

    public static function emptyKit(): self
    {
        return new self('The ad copy model returned no usable content for any advertising kit section.');
    }
}
