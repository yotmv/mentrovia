<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum ValidationDecision: string
{
    use HasOptions;

    case ApprovedCurrent = 'approved_current';
    case ApprovedWithCaveats = 'approved_with_caveats';
    case NeedsSourceRefresh = 'needs_source_refresh';
    case NeedsProfessionalReview = 'needs_professional_review';
    case ConflictingSources = 'conflicting_sources';
    case NotEnoughInformation = 'not_enough_information';
    case AdminReviewRequired = 'admin_review_required';

    public function label(): string
    {
        return match ($this) {
            self::ApprovedCurrent => 'Approved current',
            self::ApprovedWithCaveats => 'Approved with caveats',
            self::NeedsSourceRefresh => 'Needs source refresh',
            self::NeedsProfessionalReview => 'Needs professional review',
            self::ConflictingSources => 'Conflicting sources',
            self::NotEnoughInformation => 'Not enough information',
            self::AdminReviewRequired => 'Admin review required',
        };
    }
}
