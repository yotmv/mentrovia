<?php

namespace App\Services;

use App\Enums\BusinessStage;
use App\Models\Business;

/**
 * Deterministically classifies a business into one of the four v1 stages.
 *
 * Precedence (top wins):
 * 1. Any employees → ExistingWithEmployees. A formal entity with employees
 *    still classifies here, because employer/payroll obligations are the more
 *    urgent guidance (spec stage 3 explicitly includes LLC owners).
 * 2. Formal registered entity → ExistingEntity.
 * 3. Already operating (first sale, revenue, or an active DBA) → ExistingDba.
 * 4. Otherwise → StartingFromScratch.
 */
class StageClassifier
{
    public function classify(Business $business): BusinessStage
    {
        if ($business->employee_count > 0 || $business->first_employee_on !== null) {
            return BusinessStage::ExistingWithEmployees;
        }

        if ($business->legal_structure->isFormalEntity()) {
            return BusinessStage::ExistingEntity;
        }

        if ($business->isOperating()) {
            return BusinessStage::ExistingDba;
        }

        return BusinessStage::StartingFromScratch;
    }
}
