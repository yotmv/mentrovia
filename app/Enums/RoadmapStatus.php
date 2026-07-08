<?php

namespace App\Enums;

enum RoadmapStatus: string
{
    case Complete = 'complete';
    case ToDo = 'to_do';
    case NeedsInfo = 'needs_info';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Complete',
            self::ToDo => 'To do',
            self::NeedsInfo => 'Needs info',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::ToDo || $this === self::NeedsInfo;
    }
}
