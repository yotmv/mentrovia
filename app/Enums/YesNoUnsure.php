<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum YesNoUnsure: string
{
    use HasOptions;

    case Yes = 'yes';
    case No = 'no';
    case Unsure = 'unsure';

    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::No => 'No',
            self::Unsure => 'Not sure',
        };
    }

    public function isYes(): bool
    {
        return $this === self::Yes;
    }

    public function isUnsure(): bool
    {
        return $this === self::Unsure;
    }
}
