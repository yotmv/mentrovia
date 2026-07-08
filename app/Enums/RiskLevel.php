<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum RiskLevel: string
{
    use HasOptions;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    /**
     * How many days a verified article stays current before requiring review.
     */
    public function reviewIntervalDays(): int
    {
        return match ($this) {
            self::Low => 365,
            self::Medium => 180,
            self::High => 90,
        };
    }
}
