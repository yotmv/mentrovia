<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum MonthlyRevenueRange: string
{
    use HasOptions;

    case None = 'none';
    case Under1K = 'under_1k';
    case From1KTo5K = '1k_to_5k';
    case From5KTo10K = '5k_to_10k';
    case From10KTo25K = '10k_to_25k';
    case Over25K = 'over_25k';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No revenue yet',
            self::Under1K => 'Under $1,000',
            self::From1KTo5K => '$1,000 – $5,000',
            self::From5KTo10K => '$5,000 – $10,000',
            self::From10KTo25K => '$10,000 – $25,000',
            self::Over25K => 'Over $25,000',
        };
    }
}
