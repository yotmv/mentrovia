<?php

namespace App\Ai\Images;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

class ImageModelArbiter
{
    /**
     * Ask a free LLM to make the final ordered pick from the shortlist.
     * Returns the validated choice ids, or null when the arbiter is
     * disabled or fails — generation must never block on it.
     *
     * @param  Collection<int, ImageModelCandidate>  $candidates
     * @return array<int, string>|null
     */
    public function pick(ImageRequirements $requirements, Collection $candidates, int $count): ?array
    {
        $config = config('photostudio.chooser.llm');

        if (! ($config['enabled'] ?? false)
            || blank(config("ai.providers.{$config['provider']}.key"))
            || $candidates->count() <= 1) {
            return null;
        }

        $validChoiceIds = $candidates->map(
            fn (ImageModelCandidate $candidate) => $candidate->choiceId()
        )->all();

        $cacheKey = 'photostudio:arbiter:'.md5((string) json_encode([
            $config['model'],
            $requirements->toArray(),
            $validChoiceIds,
            $count,
        ]));

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = agent(
                instructions: $this->instructions(),
                schema: fn (JsonSchema $schema) => [
                    'choice_ids' => $schema->array()->items($schema->string())->required(),
                    'reason' => $schema->string()->required(),
                ],
            )->prompt(
                $this->prompt($requirements, $candidates, $count),
                provider: $config['provider'],
                model: $config['model'],
                timeout: $config['timeout'] ?? 20,
            );

            $structured = $response instanceof StructuredAgentResponse ? $response->toArray() : [];

            $choiceIds = collect((array) ($structured['choice_ids'] ?? []))
                ->filter(fn ($choiceId) => is_string($choiceId) && in_array($choiceId, $validChoiceIds, true))
                ->unique()
                ->values()
                ->all();

            if ($choiceIds === []) {
                Log::warning('Image model arbiter returned no valid choice ids; falling back to heuristic ranking.', [
                    'response' => $structured['choice_ids'] ?? null,
                ]);

                return null;
            }

            Cache::put($cacheKey, $choiceIds, now()->addHours($config['cache_ttl_hours'] ?? 24));

            return $choiceIds;
        } catch (Throwable $e) {
            Log::warning('Image model arbiter failed; falling back to heuristic ranking.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function instructions(): string
    {
        return <<<'INSTRUCTIONS'
        You select image generation models for a task. Choose the models offering the best VALUE, not the best raw quality.
        Rules: favor the cheaper model when quality is close; favor vendor-recommended models when value is close.
        Return exactly the requested number of choice_ids, best first, copied verbatim from the candidate list.
        INSTRUCTIONS;
    }

    /**
     * @param  Collection<int, ImageModelCandidate>  $candidates
     */
    protected function prompt(ImageRequirements $requirements, Collection $candidates, int $count): string
    {
        $digest = $candidates->map(
            fn (ImageModelCandidate $candidate) => $candidate->toDigest()
        )->values()->all();

        return sprintf(
            "Task requirements:\n%s\n\nCandidates:\n%s\n\nPick the %d best-value models, ordered best first.",
            json_encode($requirements->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            json_encode($digest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $count,
        );
    }
}
