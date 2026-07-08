<?php

namespace App\Enums;

enum PhotoMode: string
{
    case Cleanup = 'cleanup';
    case Recreate = 'recreate';

    public function label(): string
    {
        return match ($this) {
            self::Cleanup => 'Cleanup',
            self::Recreate => 'Recreate',
        };
    }
}
