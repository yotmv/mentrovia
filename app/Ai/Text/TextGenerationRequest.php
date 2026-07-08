<?php

namespace App\Ai\Text;

use App\Enums\TextGenerationRole;

class TextGenerationRequest
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly TextGenerationRole $role,
        public readonly string $prompt,
        public readonly array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(TextGenerationRole|string $role, string $prompt, array $context = []): self
    {
        return new self(
            $role instanceof TextGenerationRole ? $role : TextGenerationRole::from($role),
            $prompt,
            $context,
        );
    }

    public function promptWithContext(): string
    {
        if ($this->context === []) {
            return $this->prompt;
        }

        return sprintf(
            "%s\n\nContext:\n%s",
            $this->prompt,
            json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
