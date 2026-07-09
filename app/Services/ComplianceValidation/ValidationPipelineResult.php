<?php

namespace App\Services\ComplianceValidation;

use App\Enums\ValidationDecision;
use App\Enums\ValidationRunStatus;
use App\Models\ValidationRun;

class ValidationPipelineResult
{
    public function __construct(
        public readonly ValidationRun $run,
        public readonly ValidationRunStatus $status,
        public readonly ValidationDecision $decision,
    ) {}
}
