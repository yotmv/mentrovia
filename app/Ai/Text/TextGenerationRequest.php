<?php

namespace App\Ai\Text;

use App\Enums\AiModelPurpose;
use App\Enums\TextGenerationRole;
use App\Models\User;

class TextGenerationRequest
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly TextGenerationRole $role,
        public readonly string $prompt,
        public readonly array $context = [],
        public readonly ?int $userId = null,
        public readonly ?AiModelPurpose $purpose = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public static function make(TextGenerationRole|string $role, string $prompt, array $context = [], ?User $user = null, ?AiModelPurpose $purpose = null): self
    {
        return new self(
            $role instanceof TextGenerationRole ? $role : TextGenerationRole::from($role),
            $prompt,
            $context,
            $user?->id,
            $purpose,
        );
    }

    public function resolvedPurpose(): AiModelPurpose
    {
        return $this->purpose ?? match ($this->role) {
            TextGenerationRole::AdvisorAnswer => AiModelPurpose::LongText,
            default => AiModelPurpose::ShortText,
        };
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
