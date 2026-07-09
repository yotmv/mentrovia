<?php

namespace App\Enums;

enum FluxUiKit: string
{
    case FluxFree = 'flux-free';
    case FluxPro = 'flux-pro';

    /**
     * The kit configured for this install; unknown values fall back to free
     * so open-source installs without a Flux Pro license always render.
     */
    public static function current(): self
    {
        $kit = config('flux-ui.kit');

        return is_string($kit) ? (self::tryFrom($kit) ?? self::FluxFree) : self::FluxFree;
    }

    public function isPro(): bool
    {
        return $this === self::FluxPro;
    }
}
