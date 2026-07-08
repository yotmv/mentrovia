<?php

namespace App\Ai\Images;

use Illuminate\Support\Str;

class ImageModelCandidate
{
    public ?float $score = null;

    public ?float $effectiveCost = null;

    public ?int $effectiveQuality = null;

    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $profile,
    ) {}

    /**
     * The identifier the LLM arbiter echoes back for this candidate.
     */
    public function choiceId(): string
    {
        return $this->provider.'::'.$this->model;
    }

    /**
     * The model family used to enforce selection diversity (author prefix
     * of the slug, or the provider for unprefixed models). Aliases fold
     * vendors that publish under different prefixes per provider.
     */
    public function family(): string
    {
        $family = Str::contains($this->model, '/')
            ? Str::before($this->model, '/')
            : $this->provider;

        return match ($family) {
            'bytedance-seed' => 'bytedance',
            'x-ai' => 'xai',
            default => $family,
        };
    }

    public function quality(): int
    {
        return (int) ($this->profile['quality'] ?? 0);
    }

    /**
     * The curated quality score for instruction-following edits of an
     * existing image. Some models produce great first generations but
     * follow edit instructions poorly; their profiles flag that with a
     * separate, lower "edit_quality".
     */
    public function editQuality(): int
    {
        return (int) ($this->profile['edit_quality'] ?? $this->quality());
    }

    /**
     * The quality score that applies to the given task.
     */
    public function qualityFor(ImageRequirements $requirements): int
    {
        return $requirements->isEditTask() ? $this->editQuality() : $this->quality();
    }

    public function usdPerImage(): float
    {
        return (float) ($this->profile['usd_per_image'] ?? 0.0);
    }

    /**
     * What one attached reference image adds to the bill. Token-billed
     * models (FLUX, GPT-image) charge meaningfully per reference; flat
     * per-image models charge nothing.
     */
    public function usdPerReferenceImage(): float
    {
        return (float) ($this->profile['usd_per_reference_image'] ?? 0.0);
    }

    /**
     * The expected total cost of one generation including the reference
     * images that will actually be sent (clamped to the model's limit).
     */
    public function effectiveUsdPerImage(int $referenceImages): float
    {
        return $this->usdPerImage()
            + $this->usdPerReferenceImage() * min(max(0, $referenceImages), $this->maxReferenceImages());
    }

    public function popularityRank(): ?int
    {
        return $this->profile['popularity_rank'] ?? null;
    }

    public function isRecommended(): bool
    {
        return (bool) ($this->profile['recommended'] ?? false);
    }

    public function supports(string $capability): bool
    {
        return (bool) ($this->profile['supports_'.$capability] ?? false);
    }

    public function output(): string
    {
        return $this->profile['output'] ?? 'raster';
    }

    public function maxReferenceImages(): int
    {
        return (int) ($this->profile['max_reference_images'] ?? 1);
    }

    /**
     * Provider-specific input field mapping (Replicate models name their
     * image input field inconsistently).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return $this->profile['input_schema'] ?? [];
    }

    /**
     * The name of an external (BYOK) key this model requires, if any.
     */
    public function externalKey(): ?string
    {
        return $this->profile['external_key'] ?? null;
    }

    /**
     * A compact digest for logs and the arbiter prompt.
     *
     * @return array<string, mixed>
     */
    public function toDigest(): array
    {
        return [
            'choice_id' => $this->choiceId(),
            'quality' => $this->effectiveQuality ?? $this->quality(),
            'usd_per_image' => round($this->effectiveCost ?? $this->usdPerImage(), 4),
            'popularity_rank' => $this->popularityRank(),
            'recommended' => $this->isRecommended(),
            'heuristic_score' => $this->score !== null ? round($this->score, 4) : null,
        ];
    }
}
