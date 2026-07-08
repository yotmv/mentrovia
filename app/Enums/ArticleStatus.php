<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ArticleStatus: string
{
    use HasOptions;

    case Draft = 'draft';
    case Published = 'published';
    case NeedsReview = 'needs_review';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Published => 'Published',
            self::NeedsReview => 'Needs review',
            self::Archived => 'Archived',
        };
    }
}
