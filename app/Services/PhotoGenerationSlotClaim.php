<?php

namespace App\Services;

use App\Models\PhotoGenerationSlot;

final readonly class PhotoGenerationSlotClaim
{
    public function __construct(
        public PhotoGenerationSlot $slot,
        public string $executionToken,
        public int $fence,
        public bool $resumeStaged,
    ) {}
}
