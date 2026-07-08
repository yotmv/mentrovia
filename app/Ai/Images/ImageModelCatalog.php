<?php

namespace App\Ai\Images;

use App\Ai\Images\Exceptions\UnknownImageModelException;
use Illuminate\Support\Collection;

class ImageModelCatalog
{
    /**
     * All profiled models across every provider.
     *
     * @return Collection<int, ImageModelCandidate>
     */
    public function all(): Collection
    {
        return collect((array) config('photostudio.models', []))
            ->flatMap(function (array $models, string $provider) {
                return collect($models)->map(
                    fn (array $profile, string $model) => new ImageModelCandidate($provider, $model, $profile)
                )->values();
            })
            ->values();
    }

    /**
     * Look up a single model, rejecting anything outside the allowlist.
     *
     * @throws UnknownImageModelException
     */
    public function find(string $provider, string $model): ImageModelCandidate
    {
        // Model slugs contain dots (e.g. gemini-2.5), so dot-notation config
        // lookups would split them; index into the provider array instead.
        $profile = config("photostudio.models.{$provider}", [])[$model] ?? null;

        if (! is_array($profile)) {
            throw UnknownImageModelException::for($provider, $model);
        }

        return new ImageModelCandidate($provider, $model, $profile);
    }
}
