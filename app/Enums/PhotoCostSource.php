<?php

namespace App\Enums;

enum PhotoCostSource: string
{
    case Provider = 'provider';
    case Estimate = 'estimate';

    public function label(): string
    {
        return match ($this) {
            self::Provider => 'Provider-billed',
            self::Estimate => 'Estimated',
        };
    }
}
