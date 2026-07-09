<?php

namespace App\Services\ComplianceValidation;

use App\Ai\Text\TextGenerationResult;
use App\Enums\TextGenerationRole;
use App\Enums\ValidationDecision;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ValidationModelResponse
{
    /**
     * @param  array<int, string>  $flags
     * @param  array<int, string>  $concerns
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly TextGenerationRole $role,
        public readonly string $provider,
        public readonly string $model,
        public readonly ValidationDecision $decision,
        public readonly ?int $confidence,
        public readonly array $flags,
        public readonly array $concerns,
        public readonly array $rawResponse,
        public readonly array $metadata,
    ) {}

    public static function fromTextResult(TextGenerationResult $result): self
    {
        $payload = self::decodePayload($result->text);
        $decisionValue = Arr::get($payload, 'decision', Arr::get($payload, 'vote'));
        $decision = is_string($decisionValue)
            ? ValidationDecision::tryFrom($decisionValue)
            : null;

        if (! $decision instanceof ValidationDecision) {
            $decision = ValidationDecision::AdminReviewRequired;
            $payload['parser_error'] = 'Response did not include a recognized validation decision.';
        }

        $confidence = Arr::get($payload, 'confidence');

        return new self(
            role: $result->role,
            provider: $result->provider,
            model: $result->model,
            decision: $decision,
            confidence: is_numeric($confidence) ? max(0, min(100, (int) $confidence)) : null,
            flags: self::strings(Arr::get($payload, 'flags', [])),
            concerns: self::concernsFromPayload($payload),
            rawResponse: $payload,
            metadata: [
                ...$result->metadata,
                'config_version' => $result->configVersion,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    protected static function concernsFromPayload(array $payload): array
    {
        $concerns = self::strings(Arr::get($payload, 'concerns', []));
        $rationale = Arr::get($payload, 'rationale', Arr::get($payload, 'summary'));

        if (is_string($rationale) && filled($rationale)) {
            $concerns[] = $rationale;
        }

        return array_values(array_unique($concerns));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function decodePayload(string $text): array
    {
        $json = Str::of($text)
            ->trim()
            ->replaceMatches('/^```(?:json)?\s*/', '')
            ->replaceMatches('/\s*```$/', '')
            ->toString();

        $payload = json_decode($json, true);

        if (is_array($payload)) {
            return $payload;
        }

        return [
            'raw_text' => $text,
            'parser_error' => 'Response was not valid JSON.',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function strings(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn (mixed $item): bool => is_string($item) && filled($item))
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }
}
