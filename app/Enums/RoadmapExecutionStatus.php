<?php

namespace App\Enums;

enum RoadmapExecutionStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Blocked = 'blocked';
    case Complete = 'complete';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Blocked => 'Blocked',
            self::Complete => 'Complete',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Complete, self::NotApplicable], true);
    }
}
