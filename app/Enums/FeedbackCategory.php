<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum FeedbackCategory: string
{
    use HasOptions;

    case Bug = 'bug';
    case Content = 'content';
    case Idea = 'idea';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Something is not working',
            self::Content => 'Guidance or source feedback',
            self::Idea => 'Feature idea',
            self::Other => 'Other feedback',
        };
    }
}
