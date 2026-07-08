<?php

namespace App\Services;

use App\Enums\RoadmapPhase;
use App\Enums\RoadmapPriority;
use App\Enums\RoadmapStatus;

/**
 * A single roadmap entry derived from the business profile.
 */
final readonly class RoadmapItem
{
    public function __construct(
        public string $key,
        public RoadmapPhase $phase,
        public string $title,
        public string $whyItMatters,
        public RoadmapPriority $priority,
        public RoadmapStatus $status,
        public ?string $reviewer = null,
    ) {}

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
