<?php

namespace App\Services\Branding;

use RuntimeException;

class BrandKitGenerationException extends RuntimeException
{
    public static function unstructuredResponse(): self
    {
        return new self('The brand copy model did not return a structured JSON brand kit.');
    }

    public static function unknownSection(string $section): self
    {
        return new self("Brand kit section [{$section}] is not regenerable.");
    }

    public static function emptySection(string $section): self
    {
        return new self("The brand copy model returned no usable content for brand kit section [{$section}].");
    }
}
