<?php

namespace App\Enums;

enum PhotoTextSource: string
{
    case User = 'user';
    case Auto = 'auto';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Provided by user',
            self::Auto => 'Auto-generated',
        };
    }
}
