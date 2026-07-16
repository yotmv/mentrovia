<?php

namespace App\Enums;

enum ProfileFreshness: string
{
    case Current = 'current';
    case Stale = 'stale';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Stale => 'Profile changed',
            self::Unknown => 'Input version not recorded',
        };
    }
}
