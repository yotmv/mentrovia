<?php

namespace Tests\Feature\Ai;

use App\Ai\Images\ImageModelChooser;
use App\Ai\Images\ImageRequirements;
use Laravel\Ai\StructuredAnonymousAgent;
use Tests\TestCase;

class ImageModelArbiterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.openrouter.key' => 'test-key',
            'ai.providers.replicate.key' => 'test-key',
            'ai.providers.stability.key' => 'test-key',
            'photostudio.chooser.llm.enabled' => true,
            'photostudio.provider' => 'auto',
        ]);
    }

    protected function requirements(): ImageRequirements
    {
        return new ImageRequirements(requiresImageInput: true, maxUsdPerImage: 0.10);
    }

    public function test_arbiter_pick_leads_the_selection(): void
    {
        StructuredAnonymousAgent::fake([[
            'choice_ids' => ['openrouter::google/gemini-2.5-flash-image', 'openrouter::x-ai/grok-imagine-image-quality'],
            'reason' => 'Cheap editing king, then a different family.',
        ]]);

        $selected = app(ImageModelChooser::class)->chooseMany($this->requirements(), 3);

        $this->assertSame(
            'openrouter::google/gemini-2.5-flash-image',
            $selected->first()->choiceId(),
        );
        $this->assertSame('openrouter::x-ai/grok-imagine-image-quality', $selected->get(1)->choiceId());

        StructuredAnonymousAgent::assertPrompted(
            fn ($prompt) => str_contains($prompt->prompt, 'openrouter::google/gemini-2.5-flash-image')
        );
    }

    public function test_hallucinated_choice_ids_fall_back_to_heuristic_order(): void
    {
        StructuredAnonymousAgent::fake([[
            'choice_ids' => ['made-up::not/real'],
            'reason' => 'Confidently wrong.',
        ]]);

        $withArbiter = app(ImageModelChooser::class)->chooseMany($this->requirements(), 3);

        config(['photostudio.chooser.llm.enabled' => false]);

        $heuristic = app(ImageModelChooser::class)->chooseMany($this->requirements(), 3);

        $this->assertSame(
            $heuristic->map(fn ($candidate) => $candidate->choiceId())->all(),
            $withArbiter->map(fn ($candidate) => $candidate->choiceId())->all(),
        );
    }

    public function test_arbiter_decision_is_cached_across_calls(): void
    {
        StructuredAnonymousAgent::fake([[
            'choice_ids' => ['openrouter::x-ai/grok-imagine-image-quality'],
            'reason' => 'One-time pick.',
        ]]);

        $chooser = app(ImageModelChooser::class);

        $first = $chooser->chooseMany($this->requirements(), 3);
        $second = $chooser->chooseMany($this->requirements(), 3);

        // A second LLM call would consume an undefined fake response and fall
        // back to heuristic order, so identical output proves the cache hit.
        $this->assertSame('openrouter::x-ai/grok-imagine-image-quality', $first->first()->choiceId());
        $this->assertSame('openrouter::x-ai/grok-imagine-image-quality', $second->first()->choiceId());
    }

    public function test_disabled_arbiter_never_prompts(): void
    {
        config(['photostudio.chooser.llm.enabled' => false]);

        StructuredAnonymousAgent::fake();

        app(ImageModelChooser::class)->chooseMany($this->requirements(), 3);

        StructuredAnonymousAgent::assertNeverPrompted();
    }

    public function test_missing_llm_provider_key_skips_the_arbiter(): void
    {
        config([
            'photostudio.chooser.llm.provider' => 'groq',
            'ai.providers.groq.key' => null,
        ]);

        StructuredAnonymousAgent::fake();

        app(ImageModelChooser::class)->chooseMany($this->requirements(), 3);

        StructuredAnonymousAgent::assertNeverPrompted();
    }
}
