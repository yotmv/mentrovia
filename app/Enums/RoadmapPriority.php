<?php

namespace App\Enums;

enum RoadmapPriority: string
{
    case Required = 'required';
    case Recommended = 'recommended';
    case Optional = 'optional';

    public function label(): string
    {
        return match ($this) {
            self::Required => 'Required',
            self::Recommended => 'Recommended',
            self::Optional => 'Optional',
        };
    }

    /**
     * Sort weight: lower comes first.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Required => 0,
            self::Recommended => 1,
            self::Optional => 2,
        };
    }
}
