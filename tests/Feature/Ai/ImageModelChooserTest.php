<?php

namespace Tests\Feature\Ai;

use App\Ai\Images\Exceptions\NoUsableImageModelException;
use App\Ai\Images\Exceptions\UnknownImageModelException;
use App\Ai\Images\ImageModelCatalog;
use App\Ai\Images\ImageModelChooser;
use App\Ai\Images\ImageRequirements;
use Tests\TestCase;

class ImageModelChooserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.providers.openrouter.key' => 'test-key',
            'ai.providers.replicate.key' => 'test-key',
            'ai.providers.stability.key' => 'test-key',
            'photostudio.chooser.llm.enabled' => false,
            'photostudio.provider' => 'auto',
        ]);
    }

    protected function chooser(): ImageModelChooser
    {
        return app(ImageModelChooser::class);
    }

    public function test_models_from_providers_without_api_keys_are_filtered_out(): void
    {
        config([
            'ai.providers.replicate.key' => null,
            'ai.providers.stability.key' => null,
        ]);

        $providers = $this->chooser()
            ->ranked(new ImageRequirements)
            ->map(fn ($candidate) => $candidate->provider)
            ->unique();

        $this->assertSame(['openrouter'], $providers->values()->all());
    }

    public function test_image_input_and_editing_requirements_filter_candidates(): void
    {
        $ranked = $this->chooser()->ranked(new ImageRequirements(
            requiresImageInput: true,
            requiresEditing: true,
            maxUsdPerImage: 1.0,
        ));

        $ids = $ranked->map(fn ($candidate) => $candidate->choiceId());

        $this->assertNotContains('stability::core', $ids->all());
        $this->assertNotContains('openrouter::x-ai/grok-imagine-image-quality', $ids->all());
        $this->assertContains('openrouter::google/gemini-2.5-flash-image', $ids->all());
    }

    public function test_edit_tasks_judge_candidates_on_their_edit_quality_score(): void
    {
        config(['photostudio.models' => [
            'openrouter' => [
                'great/creator' => $this->profile(quality: 90, usd: 0.03) + ['edit_quality' => 40],
                'solid/editor' => $this->profile(quality: 75, usd: 0.05),
            ],
        ]]);

        $editRequirements = new ImageRequirements(
            requiresImageInput: true,
            requiresEditing: true,
            minQuality: 60,
            maxUsdPerImage: 1.0,
            task: ImageRequirements::TASK_EDIT,
        );

        $editIds = $this->chooser()->ranked($editRequirements)
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertNotContains('openrouter::great/creator', $editIds->all());
        $this->assertContains('openrouter::solid/editor', $editIds->all());

        $generateIds = $this->chooser()
            ->ranked(new ImageRequirements(requiresImageInput: true, requiresEditing: true, minQuality: 60, maxUsdPerImage: 1.0))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertContains('openrouter::great/creator', $generateIds->all());
    }

    public function test_an_above_threshold_edit_quality_still_demotes_the_ranking(): void
    {
        config(['photostudio.models' => [
            'openrouter' => [
                'great/creator' => $this->profile(quality: 90, usd: 0.05) + ['edit_quality' => 65],
                'solid/editor' => $this->profile(quality: 80, usd: 0.05),
            ],
        ]]);

        $requirements = new ImageRequirements(maxUsdPerImage: 1.0, task: ImageRequirements::TASK_EDIT);

        $ranked = $this->chooser()->ranked($requirements);

        $this->assertSame('openrouter::solid/editor', $ranked->first()->choiceId());
        $this->assertSame(65, $ranked->last()->effectiveQuality);
    }

    public function test_flagged_poor_editors_are_excluded_from_edit_tasks(): void
    {
        $editIds = $this->chooser()
            ->ranked(new ImageRequirements(
                requiresImageInput: true,
                requiresEditing: true,
                minQuality: 60,
                maxUsdPerImage: 0.10,
                referenceImageCount: 1,
                task: ImageRequirements::TASK_EDIT,
            ))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertNotContains('replicate::bytedance/seedream-4', $editIds->all());
        $this->assertNotContains('replicate::black-forest-labs/flux-kontext-pro', $editIds->all());
        $this->assertContains('openrouter::google/gemini-2.5-flash-image', $editIds->all());

        $generateIds = $this->chooser()
            ->ranked(new ImageRequirements(
                requiresImageInput: true,
                requiresEditing: true,
                minQuality: 60,
                maxUsdPerImage: 0.10,
                referenceImageCount: 1,
            ))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertContains('replicate::bytedance/seedream-4', $generateIds->all());
        $this->assertContains('replicate::black-forest-labs/flux-kontext-pro', $generateIds->all());
    }

    public function test_gpt_image_1_mini_is_a_usable_budget_candidate_for_generation_and_edits(): void
    {
        $defaults = [
            'requiresImageInput' => true,
            'requiresEditing' => true,
            'minQuality' => 60,
            'maxUsdPerImage' => 0.10,
            'referenceImageCount' => 1,
        ];

        $generateIds = $this->chooser()
            ->ranked(new ImageRequirements(...$defaults))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertContains('openrouter::openai/gpt-image-1-mini', $generateIds->all());

        $editIds = $this->chooser()
            ->ranked(new ImageRequirements(...$defaults, task: ImageRequirements::TASK_EDIT))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertContains('openrouter::openai/gpt-image-1-mini', $editIds->all());
    }

    public function test_non_square_aspect_ratio_requires_native_support(): void
    {
        $ids = $this->chooser()
            ->ranked(new ImageRequirements(aspectRatio: '3:2', maxUsdPerImage: 1.0))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertNotContains('openrouter::black-forest-labs/flux.2-pro', $ids->all());
        $this->assertNotContains('openrouter::openai/gpt-image-2', $ids->all());
        $this->assertContains('openrouter::google/gemini-2.5-flash-image', $ids->all());

        $squareIds = $this->chooser()
            ->ranked(new ImageRequirements(aspectRatio: '1:1', maxUsdPerImage: 1.0))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertContains('openrouter::black-forest-labs/flux.2-pro', $squareIds->all());
    }

    public function test_quality_and_cost_thresholds_are_hard_filters(): void
    {
        $ids = $this->chooser()
            ->ranked(new ImageRequirements(minQuality: 85, maxUsdPerImage: 0.10))
            ->map(fn ($candidate) => $candidate->choiceId());

        $this->assertContains('openrouter::google/gemini-3.1-flash-image', $ids->all());
        $this->assertContains('openrouter::x-ai/grok-imagine-image-quality', $ids->all());
        $this->assertNotContains('openrouter::openai/gpt-image-2', $ids->all(), 'Too expensive at $0.125.');
        $this->assertNotContains('openrouter::google/gemini-2.5-flash-image', $ids->all(), 'Quality below 85.');
    }

    public function test_reference_image_costs_count_against_the_price_cap(): void
    {
        $withOneReference = $this->chooser()
            ->ranked(new ImageRequirements(requiresImageInput: true, maxUsdPerImage: 0.10, referenceImageCount: 1))
            ->map(fn ($candidate) => $candidate->choiceId());

        // 0.03 output + 1 × 0.03 reference = 0.06, still under the cap.
        $this->assertContains('openrouter::black-forest-labs/flux.2-pro', $withOneReference->all());

        $withFourReferences = $this->chooser()
            ->ranked(new ImageRequirements(requiresImageInput: true, maxUsdPerImage: 0.10, referenceImageCount: 4))
            ->map(fn ($candidate) => $candidate->choiceId());

        // 0.03 output + 4 × 0.03 references = 0.15, over the cap.
        $this->assertNotContains('openrouter::black-forest-labs/flux.2-pro', $withFourReferences->all());
        $this->assertContains('replicate::bytedance/seedream-4', $withFourReferences->all());
    }

    public function test_reference_image_costs_reorder_the_value_ranking(): void
    {
        $requirements = new ImageRequirements(requiresImageInput: true, maxUsdPerImage: 1.0, referenceImageCount: 4);

        $ranked = $this->chooser()->ranked($requirements);

        $flux = $ranked->first(fn ($candidate) => $candidate->choiceId() === 'openrouter::black-forest-labs/flux.2-pro');
        $gemini = $ranked->first(fn ($candidate) => $candidate->choiceId() === 'openrouter::google/gemini-2.5-flash-image');

        $this->assertEqualsWithDelta(0.15, $flux->effectiveCost, 0.0001);
        $this->assertGreaterThan($flux->score, $gemini->score, 'Reference-hungry pricing should demote FLUX below flat-priced models.');
    }

    public function test_cheap_decent_model_outranks_expensive_flagship(): void
    {
        config(['photostudio.models' => [
            'openrouter' => [
                'cheap/decent' => $this->profile(quality: 78, usd: 0.02),
                'fancy/flagship' => $this->profile(quality: 95, usd: 0.20),
            ],
        ]]);

        $ranked = $this->chooser()->ranked(new ImageRequirements(maxUsdPerImage: 1.0));

        $this->assertSame('openrouter::cheap/decent', $ranked->first()->choiceId());
    }

    public function test_recommended_breaks_score_ties(): void
    {
        config(['photostudio.models' => [
            'openrouter' => [
                'a/plain' => $this->profile(quality: 80, usd: 0.05),
                'b/endorsed' => $this->profile(quality: 80, usd: 0.05, recommended: true),
            ],
        ]]);

        $ranked = $this->chooser()->ranked(new ImageRequirements(maxUsdPerImage: 1.0));

        $this->assertSame('openrouter::b/endorsed', $ranked->first()->choiceId());
    }

    public function test_choose_many_prefers_family_diversity_then_pads(): void
    {
        config(['photostudio.models' => [
            'openrouter' => [
                'acme/one' => $this->profile(quality: 90, usd: 0.02),
                'acme/two' => $this->profile(quality: 88, usd: 0.02),
                'acme/three' => $this->profile(quality: 86, usd: 0.02),
                'other/model' => $this->profile(quality: 70, usd: 0.05),
            ],
        ]]);

        $selected = $this->chooser()->chooseMany(new ImageRequirements(maxUsdPerImage: 1.0), 3);

        $this->assertSame(
            ['openrouter::acme/one', 'openrouter::other/model', 'openrouter::acme/two'],
            $selected->map(fn ($candidate) => $candidate->choiceId())->all(),
        );
    }

    public function test_unprofiled_models_are_rejected(): void
    {
        $this->expectException(UnknownImageModelException::class);

        app(ImageModelCatalog::class)->find('openrouter', 'not/profiled');
    }

    public function test_choose_many_fails_loudly_when_no_model_is_usable(): void
    {
        config([
            'ai.providers.openrouter.key' => null,
            'ai.providers.replicate.key' => null,
            'ai.providers.stability.key' => null,
        ]);

        $this->expectException(NoUsableImageModelException::class);

        $this->chooser()->chooseMany(new ImageRequirements, 3);
    }

    public function test_pinned_provider_bypasses_the_chooser(): void
    {
        config([
            'photostudio.provider' => 'openrouter',
            'photostudio.model' => 'google/gemini-2.5-flash-image',
        ]);

        $selected = $this->chooser()->forConfiguredProvider(ImageRequirements::forPhotoBatch(), 3);

        $this->assertCount(1, $selected);
        $this->assertSame('openrouter::google/gemini-2.5-flash-image', $selected->first()->choiceId());
    }

    public function test_pinned_unprofiled_model_is_rejected(): void
    {
        config([
            'photostudio.provider' => 'openrouter',
            'photostudio.model' => 'made/up',
        ]);

        $this->expectException(UnknownImageModelException::class);

        $this->chooser()->forConfiguredProvider(ImageRequirements::forPhotoBatch(), 3);
    }

    /**
     * @return array<string, mixed>
     */
    protected function profile(int $quality, float $usd, bool $recommended = false): array
    {
        return [
            'category' => 'best_overall',
            'output' => 'raster',
            'quality' => $quality,
            'usd_per_image' => $usd,
            'popularity_rank' => null,
            'recommended' => $recommended,
            'supports_image_input' => true,
            'supports_editing' => true,
            'supports_text_rendering' => false,
            'supports_aspect_ratio' => true,
            'supports_quality' => false,
            'supports_output_format' => false,
            'max_reference_images' => 4,
        ];
    }
}
