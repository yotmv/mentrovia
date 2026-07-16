<?php

namespace App\Exceptions;

use RuntimeException;

class OpenRouterPreflightFailed extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('OpenRouter preflight could not be completed.');
    }
}
