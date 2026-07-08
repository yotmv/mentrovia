<?php

namespace App\Ai\Images;

use App\Ai\Images\Exceptions\NoUsableImageModelException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ImageModelChooser
{
    public function __construct(
        protected ImageModelCatalog $catalog,
        protected ImageModelArbiter $arbiter,
    ) {}

    /**
     * All candidates that satisfy the hard requirements, scored on value
     * (not raw quality) and sorted best-first.
     *
     * @return Collection<int, ImageModelCandidate>
     */
    public function ranked(ImageRequirements $requirements): Collection
    {
        $candidates = $this->catalog->all()
            ->each(function (ImageModelCandidate $candidate) use ($requirements) {
                $candidate->effectiveCost = $candidate->effectiveUsdPerImage($requirements->referenceImageCount);
                $candidate->effectiveQuality = $candidate->qualityFor($requirements);
            })
            ->filter(fn (ImageModelCandidate $candidate) => $this->passes($candidate, $requirements))
            ->values();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $weights = config('photostudio.chooser.weights');
        $cheapest = $candidates->min(fn (ImageModelCandidate $candidate) => $candidate->effectiveCost);

        $candidates->each(function (ImageModelCandidate $candidate) use ($weights, $cheapest) {
            $costRatio = $candidate->effectiveCost > 0.0
                ? min(1.0, $cheapest / $candidate->effectiveCost)
                : 1.0;

            $popularity = $candidate->popularityRank() !== null
                ? max(0.0, (21 - $candidate->popularityRank()) / 20)
                : 0.5;

            $candidate->score = ($weights['quality'] * ($candidate->effectiveQuality ?? $candidate->quality()) / 100)
                + ($weights['cost'] * $costRatio)
                + ($weights['popularity'] * $popularity);
        });

        return $candidates
            ->sort(function (ImageModelCandidate $a, ImageModelCandidate $b) {
                return ($b->score <=> $a->score)
                    ?: ($b->isRecommended() <=> $a->isRecommended())
                    ?: ($a->effectiveCost <=> $b->effectiveCost)
                    ?: strcmp($a->choiceId(), $b->choiceId());
            })
            ->values();
    }

    /**
     * Pick the single best-value model.
     *
     * @throws NoUsableImageModelException
     */
    public function choose(ImageRequirements $requirements): ImageModelCandidate
    {
        return $this->chooseMany($requirements, 1)->first();
    }

    /**
     * Pick the top $count best-value models, at most one per model family
     * so a batch fans out across genuinely different engines.
     *
     * @return Collection<int, ImageModelCandidate>
     *
     * @throws NoUsableImageModelException
     */
    public function chooseMany(ImageRequirements $requirements, int $count): Collection
    {
        $ranked = $this->ranked($requirements);

        if ($ranked->isEmpty()) {
            throw NoUsableImageModelException::forRequirements();
        }

        $shortlist = $ranked->take((int) config('photostudio.chooser.llm.top_candidates', 8));

        $ordered = $this->applyArbiterOrder($shortlist, $ranked, $requirements, $count);

        $selected = $this->takeWithFamilyDiversity($ordered, $count);

        Log::info('Image models selected', [
            'requirements' => $requirements->toArray(),
            'selected' => $selected->map(fn (ImageModelCandidate $candidate) => $candidate->toDigest())->all(),
        ]);

        return $selected;
    }

    /**
     * Resolve the models for a batch, honoring a pinned provider/model as
     * the escape hatch when the configured provider is not "auto".
     *
     * @return Collection<int, ImageModelCandidate>
     */
    public function forConfiguredProvider(ImageRequirements $requirements, int $count): Collection
    {
        $provider = config('photostudio.provider', 'auto');

        if ($provider !== 'auto') {
            return collect([$this->catalog->find($provider, (string) config('photostudio.model'))]);
        }

        return $this->chooseMany($requirements, $count);
    }

    /**
     * Reorder the ranked list so arbiter picks lead, falling back to pure
     * heuristic order when the arbiter is disabled, fails, or hallucinates.
     *
     * @param  Collection<int, ImageModelCandidate>  $shortlist
     * @param  Collection<int, ImageModelCandidate>  $ranked
     * @return Collection<int, ImageModelCandidate>
     */
    protected function applyArbiterOrder(
        Collection $shortlist,
        Collection $ranked,
        ImageRequirements $requirements,
        int $count,
    ): Collection {
        $choiceIds = $this->arbiter->pick($requirements, $shortlist, $count);

        if ($choiceIds === null || $choiceIds === []) {
            return $ranked;
        }

        $byChoiceId = $shortlist->keyBy(fn (ImageModelCandidate $candidate) => $candidate->choiceId());

        $picked = collect($choiceIds)
            ->map(fn (string $choiceId) => $byChoiceId->get($choiceId))
            ->filter()
            ->unique(fn (ImageModelCandidate $candidate) => $candidate->choiceId())
            ->values();

        return $picked->concat(
            $ranked->reject(fn (ImageModelCandidate $candidate) => $picked->contains(
                fn (ImageModelCandidate $pick) => $pick->choiceId() === $candidate->choiceId()
            ))
        )->values();
    }

    /**
     * Take up to $count candidates preferring one per family, then fill
     * from the remainder if diversity leaves the group short.
     *
     * @param  Collection<int, ImageModelCandidate>  $ordered
     * @return Collection<int, ImageModelCandidate>
     */
    protected function takeWithFamilyDiversity(Collection $ordered, int $count): Collection
    {
        $selected = collect();
        $families = [];

        foreach ($ordered as $candidate) {
            if (count($selected) >= $count) {
                break;
            }

            if (! in_array($candidate->family(), $families, true)) {
                $selected->push($candidate);
                $families[] = $candidate->family();
            }
        }

        foreach ($ordered as $candidate) {
            if (count($selected) >= $count) {
                break;
            }

            if (! $selected->contains(fn (ImageModelCandidate $pick) => $pick->choiceId() === $candidate->choiceId())) {
                $selected->push($candidate);
            }
        }

        return $selected->values();
    }

    /**
     * Apply every hard filter; a candidate is out if any fail.
     */
    protected function passes(ImageModelCandidate $candidate, ImageRequirements $requirements): bool
    {
        if (blank(config("ai.providers.{$candidate->provider}.key"))) {
            return false;
        }

        if ($candidate->externalKey() !== null
            && blank(config("photostudio.external_keys.{$candidate->externalKey()}"))) {
            return false;
        }

        if ($candidate->output() !== $requirements->output) {
            return false;
        }

        if ($requirements->needsAspectRatioSupport() && ! $candidate->supports('aspect_ratio')) {
            return false;
        }

        if ($requirements->requiresImageInput && ! $candidate->supports('image_input')) {
            return false;
        }

        if ($requirements->requiresEditing && ! $candidate->supports('editing')) {
            return false;
        }

        if ($requirements->requiresTextRendering && ! $candidate->supports('text_rendering')) {
            return false;
        }

        if ($candidate->qualityFor($requirements) < $requirements->minQuality) {
            return false;
        }

        if ($requirements->maxUsdPerImage !== null
            && ($candidate->effectiveCost ?? $candidate->usdPerImage()) > $requirements->maxUsdPerImage) {
            return false;
        }

        return true;
    }
}
