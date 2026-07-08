<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ProjectPermission: string
{
    use HasOptions;

    case Read = 'read';
    case Write = 'write';

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Can view',
            self::Write => 'Can edit',
        };
    }
}
