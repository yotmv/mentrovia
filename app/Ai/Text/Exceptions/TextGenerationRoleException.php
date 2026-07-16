<?php

namespace App\Ai\Text\Exceptions;

use App\Enums\TextGenerationRole;
use RuntimeException;

class TextGenerationRoleException extends RuntimeException
{
    private const ConfigurationFailureCode = 1000;

    private const ProviderFailureCode = 1001;

    public static function missingRole(TextGenerationRole $role): self
    {
        return new self("Text generation role [{$role->value}] is not configured.", self::ConfigurationFailureCode);
    }

    public static function missingRoleConfig(TextGenerationRole $role, string $key): self
    {
        return new self("Text generation role [{$role->value}] is missing required config [{$key}].", self::ConfigurationFailureCode);
    }

    public static function unknownProvider(TextGenerationRole $role, string $provider): self
    {
        return new self("Text generation role [{$role->value}] references unknown AI provider [{$provider}].", self::ConfigurationFailureCode);
    }

    public static function allCandidatesFailed(TextGenerationRole $role): self
    {
        return new self("All text generation candidates failed for role [{$role->value}].", self::ProviderFailureCode);
    }

    public function isConfigurationFailure(): bool
    {
        return $this->getCode() === self::ConfigurationFailureCode;
    }

    public function isProviderFailure(): bool
    {
        return $this->getCode() === self::ProviderFailureCode;
    }
}
