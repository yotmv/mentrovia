<?php

namespace App\Ai\Text;

use App\Ai\Text\Exceptions\TextGenerationRoleException;
use App\Enums\TextGenerationRole;

class TextRoleProfile
{
    /**
     * @param  array<int, array{provider: string, model: string, timeout: int}>  $candidates
     */
    public function __construct(
        public readonly TextGenerationRole $role,
        public readonly string $instructions,
        public readonly array $candidates,
        public readonly string $configVersion,
    ) {}

    /**
     * @param  array<string, mixed>|null  $config
     */
    public static function fromConfig(TextGenerationRole $role, ?array $config, string $configVersion): self
    {
        if ($config === null) {
            throw TextGenerationRoleException::missingRole($role);
        }

        $instructions = $config['instructions'] ?? null;

        if (! is_string($instructions) || blank($instructions)) {
            throw TextGenerationRoleException::missingRoleConfig($role, 'instructions');
        }

        $fallbacks = $config['fallbacks'] ?? [];

        if (! is_array($fallbacks)) {
            throw TextGenerationRoleException::missingRoleConfig($role, 'fallbacks');
        }

        $candidates = collect([
            [
                'provider' => $config['provider'] ?? null,
                'model' => $config['model'] ?? null,
                'timeout' => $config['timeout'] ?? null,
            ],
            ...$fallbacks,
        ])
            ->filter(fn (array $candidate): bool => filled($candidate['provider'] ?? null) || filled($candidate['model'] ?? null))
            ->map(function (array $candidate) use ($role): array {
                $provider = $candidate['provider'] ?? null;
                $model = $candidate['model'] ?? null;

                if (! is_string($provider) || blank($provider)) {
                    throw TextGenerationRoleException::missingRoleConfig($role, 'provider');
                }

                if (! is_string($model) || blank($model)) {
                    throw TextGenerationRoleException::missingRoleConfig($role, 'model');
                }

                if (config("ai.providers.{$provider}") === null) {
                    throw TextGenerationRoleException::unknownProvider($role, $provider);
                }

                return [
                    'provider' => $provider,
                    'model' => $model,
                    'timeout' => (int) ($candidate['timeout'] ?? config('text-generation.default_timeout', 60)),
                ];
            })
            ->values()
            ->all();

        if ($candidates === []) {
            throw TextGenerationRoleException::missingRoleConfig($role, 'provider/model');
        }

        return new self($role, $instructions, $candidates, $configVersion);
    }
}
