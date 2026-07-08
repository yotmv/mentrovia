<?php

namespace App\Ai\Text;

use App\Enums\TextGenerationRole;

class TextGenerationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly TextGenerationRole $role,
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $configVersion,
        public readonly array $metadata = [],
    ) {}
}
