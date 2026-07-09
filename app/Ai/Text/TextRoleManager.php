<?php

namespace App\Ai\Text;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\Exceptions\TextGenerationRoleException;
use App\Ai\Text\Fakes\FakeTextRoleGenerator;
use App\Enums\TextGenerationRole;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class TextRoleManager implements TextRoleGenerator
{
    public function __construct(
        protected HumanVoiceGuidance $humanVoiceGuidance,
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
        $lastException = null;

        foreach ($profile->candidates as $candidate) {
            try {
                $response = (new TextRoleAgent(
                    $this->instructionsFor($profile),
                    $candidate['providerOptions'],
                ))->prompt(
                    $request->promptWithContext(),
                    provider: $candidate['provider'],
                    model: $candidate['model'],
                    timeout: $candidate['timeout'],
                );

                return new TextGenerationResult(
                    role: $request->role,
                    text: $response->text,
                    provider: $candidate['provider'],
                    model: $candidate['model'],
                    configVersion: $profile->configVersion,
                );
            } catch (Throwable $e) {
                $lastException = $e;

                Log::warning('Text generation role candidate failed; trying fallback if available.', [
                    'role' => $request->role->value,
                    'provider' => $candidate['provider'],
                    'model' => $candidate['model'],
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        throw TextGenerationRoleException::allCandidatesFailed($request->role, $lastException);
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
}
