<?php

namespace App\Ai\Text\Fakes;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextGenerationRequest;
use App\Ai\Text\TextGenerationResult;
use App\Enums\TextGenerationRole;
use Closure;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

class FakeTextRoleGenerator implements TextRoleGenerator
{
    /**
     * @var array<int, TextGenerationRequest>
     */
    protected array $requests = [];

    protected int $responseIndex = 0;

    protected bool $preventStrayPrompts = false;

    /**
     * @param  Closure|array<int|string, mixed>|string  $responses
     */
    public function __construct(
        protected Closure|array|string $responses = [],
    ) {}

    public function generate(TextGenerationRequest $request): TextGenerationResult
    {
        $this->requests[] = $request;

        $response = $this->nextResponse($request);

        if ($response instanceof Throwable) {
            throw $response;
        }

        if ($response instanceof TextGenerationResult) {
            return $response;
        }

        return new TextGenerationResult(
            role: $request->role,
            text: (string) $response,
            provider: 'fake',
            model: 'fake',
            configVersion: 'fake',
        );
    }

    public function preventStrayPrompts(bool $prevent = true): self
    {
        $this->preventStrayPrompts = $prevent;

        return $this;
    }

    public function assertGenerated(TextGenerationRole|string|Closure $roleOrCallback): self
    {
        $generated = collect($this->requests)->contains(function (TextGenerationRequest $request) use ($roleOrCallback): bool {
            if ($roleOrCallback instanceof Closure) {
                return (bool) $roleOrCallback($request);
            }

            $role = $roleOrCallback instanceof TextGenerationRole
                ? $roleOrCallback
                : TextGenerationRole::from($roleOrCallback);

            return $request->role === $role;
        });

        Assert::assertTrue($generated, 'Expected a text role generation request was made.');

        return $this;
    }

    public function assertNotGenerated(TextGenerationRole|string $role): self
    {
        $role = $role instanceof TextGenerationRole ? $role : TextGenerationRole::from($role);

        Assert::assertFalse(
            collect($this->requests)->contains(fn (TextGenerationRequest $request): bool => $request->role === $role),
            "Expected text role [{$role->value}] was not generated.",
        );

        return $this;
    }

    public function assertNothingGenerated(): self
    {
        Assert::assertCount(0, $this->requests, 'Expected no text role generation requests.');

        return $this;
    }

    /**
     * @return array<int, TextGenerationRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    protected function nextResponse(TextGenerationRequest $request): mixed
    {
        if ($this->responses instanceof Closure) {
            return ($this->responses)($request);
        }

        if (is_string($this->responses)) {
            return $this->responses;
        }

        if (array_key_exists($request->role->value, $this->responses)) {
            return $this->responses[$request->role->value];
        }

        if (array_key_exists($this->responseIndex, $this->responses)) {
            return $this->responses[$this->responseIndex++];
        }

        if ($this->preventStrayPrompts) {
            throw new RuntimeException("Attempted text role [{$request->role->value}] without a fake response.");
        }

        return "Fake response for text role [{$request->role->value}].";
    }
}
