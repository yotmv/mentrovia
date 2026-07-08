<?php

namespace App\Ai\Text\Exceptions;

use App\Enums\TextGenerationRole;
use RuntimeException;
use Throwable;

class TextGenerationRoleException extends RuntimeException
{
    public static function missingRole(TextGenerationRole $role): self
    {
        return new self("Text generation role [{$role->value}] is not configured.");
    }

    public static function missingRoleConfig(TextGenerationRole $role, string $key): self
    {
        return new self("Text generation role [{$role->value}] is missing required config [{$key}].");
    }

    public static function unknownProvider(TextGenerationRole $role, string $provider): self
    {
        return new self("Text generation role [{$role->value}] references unknown AI provider [{$provider}].");
    }

    public static function allCandidatesFailed(TextGenerationRole $role, ?Throwable $previous = null): self
    {
        return new self("All text generation candidates failed for role [{$role->value}].", previous: $previous);
    }
}
