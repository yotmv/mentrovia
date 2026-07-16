<?php

namespace App\Ai\Images;

use App\Enums\AiModelPurpose;
use App\Models\User;
use App\Services\Ai\AiAccountGate;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AiOperationResultMetadata;
use App\Services\Ai\AuditedAiExecutor;
use App\Services\Ai\ByokOpenRouterProviderFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Throwable;

use function Laravel\Ai\agent;

class ImageModelArbiter
{
    public function __construct(
        private AuditedAiExecutor $executor,
        private AiAccountGate $gate,
        private ByokOpenRouterProviderFactory $byokProviders,
    ) {}

    /**
     * Ask a free LLM to make the final ordered pick from the shortlist.
     * Returns the validated choice ids, or null when the arbiter is
     * disabled or fails — generation must never block on it.
     *
     * @param  Collection<int, ImageModelCandidate>  $candidates
     * @return array<int, string>|null
     */
    public function pick(
        ImageRequirements $requirements,
        Collection $candidates,
        int $count,
        ?User $user = null,
        ?int $accountId = null,
    ): ?array {
        $config = config('photostudio.chooser.llm');

        if (! $user instanceof User
            || ! ($config['enabled'] ?? false)
            || (blank(config("ai.providers.{$config['provider']}.key"))
                && $this->gate->activeByokModels($user, AiModelPurpose::Auto, $accountId) === null)
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
            $agent = agent(
                instructions: $this->instructions(),
                schema: fn (JsonSchema $schema) => [
                    'choice_ids' => $schema->array()->items($schema->string())->required(),
                    'reason' => $schema->string()->required(),
                ],
            );
            $prompt = $this->prompt($requirements, $candidates, $count);
            $response = $this->executor->execute(
                $user,
                AiModelPurpose::Auto,
                $config['provider'],
                $config['model'],
                $prompt,
                function (AiExecutionContext $context) use ($agent, $prompt, $config): AgentResponse {
                    if (! $context->usesByok()) {
                        return $agent->prompt($prompt, provider: $context->provider, model: $context->model, timeout: $config['timeout'] ?? 20);
                    }

                    $provider = $this->byokProviders->make((string) $context->credential?->secret);

                    return $provider->prompt(new AgentPrompt($agent, $prompt, [], $provider, $context->model, $config['timeout'] ?? 20));
                },
                fn (AgentResponse $response): string => $response->text,
                resultMetadata: fn (AgentResponse $response): AiOperationResultMetadata => AiOperationResultMetadata::fromResponse($response),
                account: $accountId,
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
                'exception_class' => $e::class,
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
