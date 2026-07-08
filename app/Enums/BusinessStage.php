<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum BusinessStage: string
{
    use HasOptions;

    case StartingFromScratch = 'starting_from_scratch';
    case ExistingDba = 'existing_dba';
    case ExistingWithEmployees = 'existing_with_employees';
    case ExistingEntity = 'existing_entity';

    public function label(): string
    {
        return match ($this) {
            self::StartingFromScratch => 'Starting from scratch',
            self::ExistingDba => 'Existing business (sole proprietor / DBA)',
            self::ExistingWithEmployees => 'Existing business with employees',
            self::ExistingEntity => 'Existing LLC or corporation',
        };
    }
}
