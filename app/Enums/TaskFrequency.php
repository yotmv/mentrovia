<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum TaskFrequency: string
{
    use HasOptions;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }
}
