<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum AnnualRevenueRange: string
{
    use HasOptions;

    case None = 'none';
    case Under25K = 'under_25k';
    case From25KTo100K = '25k_to_100k';
    case From100KTo250K = '100k_to_250k';
    case From250KTo500K = '250k_to_500k';
    case From500KTo1M = '500k_to_1m';
    case Over1M = 'over_1m';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No revenue yet',
            self::Under25K => 'Under $25,000',
            self::From25KTo100K => '$25,000 – $100,000',
            self::From100KTo250K => '$100,000 – $250,000',
            self::From250KTo500K => '$250,000 – $500,000',
            self::From500KTo1M => '$500,000 – $1 million',
            self::Over1M => 'Over $1 million',
        };
    }
}
