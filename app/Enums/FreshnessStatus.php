<?php

namespace App\Enums;

enum FreshnessStatus: string
{
    case Fresh = 'fresh';
    case ReviewSoon = 'review_soon';
    case Stale = 'stale';
    case MissingSources = 'missing_sources';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Fresh',
            self::ReviewSoon => 'Review soon',
            self::Stale => 'Stale',
            self::MissingSources => 'Missing sources',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Fresh => 'green',
            self::ReviewSoon => 'amber',
            self::Stale => 'red',
            self::MissingSources => 'red',
        };
    }
}
