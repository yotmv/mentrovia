<?php

namespace App\Enums;

enum PhotoKind: string
{
    case Uploaded = 'uploaded';
    case Generated = 'generated';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Generated => 'Generated',
        };
    }
}
