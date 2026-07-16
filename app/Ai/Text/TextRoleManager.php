<?php

namespace App\Ai\Text;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\Exceptions\TextGenerationRoleException;
use App\Ai\Text\Fakes\FakeTextRoleGenerator;
use App\Enums\TextGenerationRole;
use App\Exceptions\PaidAiUnavailable;
use App\Models\User;
use App\Services\Ai\AiAccountGate;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AiOperationResultMetadata;
use App\Services\Ai\AuditedAiExecutor;
use App\Services\Ai\ByokOpenRouterProviderFactory;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class TextRoleManager implements TextRoleGenerator
{
    public function __construct(
        protected HumanVoiceGuidance $humanVoiceGuidance,
        protected AuditedAiExecutor $executor,
        protected AiAccountGate $gate,
        protected ByokOpenRouterProviderFactory $byokProviders,
    ) {}

    /**
     * @param  Closure|array<int|string, mixed>|string  $responses
     */
    public static function fake(Closure|array|string $responses = []): FakeTextRoleGenerator
    {
        $fake = new FakeTextRoleGenerator($responses);

        app()->instance(TextRoleGenerator::class, $fake);

        return $fake;
    }

    public function generate(TextGenerationRequest $request): TextGenerationResult
    {
        $profile = $this->resolve($request->role);
        $user = $request->userId !== null ? User::query()->find($request->userId) : auth()->user();

        if (! $user instanceof User) {
            throw TextGenerationRoleException::allCandidatesFailed($request->role);
        }

        $byokModels = $this->gate->activeByokModels($user, $request->resolvedPurpose());
        $attempts = $byokModels !== null
            ? collect($byokModels)->map(fn (string $model): array => [
                ...$profile->candidates[0],
                'provider' => 'openrouter',
                'model' => $model,
                'expectByok' => true,
                'requestedByokModel' => $model,
            ])->all()
            : collect($profile->candidates)->map(fn (array $candidate): array => [
                ...$candidate,
                'expectByok' => false,
                'requestedByokModel' => null,
            ])->all();

        foreach ($attempts as $candidate) {
            try {
                $agent = new TextRoleAgent(
                    $this->instructionsFor($profile),
                    $candidate['providerOptions'],
                );
                $prompt = $request->promptWithContext();
                $usedProvider = $candidate['provider'];
                $usedModel = $candidate['model'];
                $response = $this->executor->execute(
                    $user,
                    $request->resolvedPurpose(),
                    $candidate['provider'],
                    $candidate['model'],
                    $prompt,
                    function (AiExecutionContext $context) use ($agent, $prompt, $candidate, &$usedProvider, &$usedModel): AgentResponse {
                        $usedProvider = $context->provider;
                        $usedModel = $context->model;

                        return $this->prompt($agent, $prompt, $candidate['timeout'], $context);
                    },
                    fn (AgentResponse $response): string => $response->text,
                    resultMetadata: fn (AgentResponse $response): AiOperationResultMetadata => AiOperationResultMetadata::fromResponse($response),
                    requestedByokModel: $candidate['requestedByokModel'],
                    expectByok: $candidate['expectByok'],
                );
                $usedProvider = $response->meta->provider ?? $usedProvider;
                $usedModel = $response->meta->model ?? $usedModel;

                return new TextGenerationResult(
                    role: $request->role,
                    text: $response->text,
                    provider: $usedProvider,
                    model: $usedModel,
                    configVersion: $profile->configVersion,
                );
            } catch (PaidAiUnavailable $exception) {
                throw $exception;
            } catch (Throwable $e) {
                Log::warning('Text generation role candidate failed; trying fallback if available.', [
                    'role' => $request->role->value,
                    'provider' => $candidate['provider'],
                    'model' => $candidate['model'],
                    'exception_class' => $e::class,
                ]);
            }
        }

        throw TextGenerationRoleException::allCandidatesFailed($request->role);
    }

    public function resolve(TextGenerationRole|string $role): TextRoleProfile
    {
        $role = $role instanceof TextGenerationRole ? $role : TextGenerationRole::from($role);

        return TextRoleProfile::fromConfig(
            $role,
            config("text-generation.roles.{$role->value}"),
            (string) config('text-generation.version', 'v1'),
        );
    }

    protected function instructionsFor(TextRoleProfile $profile): string
    {
        if (! $profile->role->usesHumanVoiceGuidance()) {
            return $profile->instructions;
        }

        return $profile->instructions."\n\nHuman voice guidance:\n".$this->humanVoiceGuidance->marketingInstructions();
    }

    private function prompt(TextRoleAgent $agent, string $prompt, int $timeout, AiExecutionContext $context): AgentResponse
    {
        if (! $context->usesByok()) {
            return $agent->prompt($prompt, provider: $context->provider, model: $context->model, timeout: $timeout);
        }

        $provider = $this->byokProviders->make((string) $context->credential?->secret);

        return $provider->prompt(new AgentPrompt($agent, $prompt, [], $provider, $context->model, $timeout));
    }
}
