<?php

namespace App\Services;

use App\Enums\LegalStructure;
use App\Enums\RiskFlag;
use App\Models\Business;

/**
 * Derives risk flags, a setup score, and missing setup items from a business
 * profile. Everything here is computed on the fly — nothing is persisted, so
 * results can never go stale when the profile is edited.
 */
class BusinessHealth
{
    /**
     * @return list<RiskFlag>
     */
    public function riskFlags(Business $business): array
    {
        $flags = [];

        if ($business->isOperating() && ! $business->has_business_bank) {
            $flags[] = RiskFlag::PersonalBankCommingling;
        }

        if ($business->isOperating() && ! $business->has_ein->isYes()) {
            $flags[] = RiskFlag::MissingEin;
        }

        if ($business->mayHaveTaxableSales() && ! $business->has_sales_tax_permit->isYes()) {
            $flags[] = RiskFlag::SalesTaxPermitGap;
        }

        if ($business->isOperating() && ! $business->has_bookkeeping) {
            $flags[] = RiskFlag::NoBookkeeping;
        }

        if ($business->employee_count > 0 && ! $business->has_payroll) {
            $flags[] = RiskFlag::EmployeesWithoutPayroll;
        }

        if ($business->legal_structure === LegalStructure::Unsure) {
            $flags[] = RiskFlag::UnclearLegalStructure;
        }

        if ($business->isOperating() && $business->legal_structure === LegalStructure::NotStarted) {
            $flags[] = RiskFlag::OperatingWithoutEntityDecision;
        }

        return $flags;
    }

    /**
     * Weighted 0-100 completeness score across the core setup items.
     */
    public function setupScore(Business $business): int
    {
        $earned = 0;
        $possible = 0;

        foreach ($this->setupItems($business) as $item) {
            if (! $item['applicable']) {
                continue;
            }

            $possible += $item['weight'];

            if ($item['complete']) {
                $earned += $item['weight'];
            }
        }

        return $possible === 0 ? 0 : (int) round($earned / $possible * 100);
    }

    /**
     * Setup items that are applicable but not yet complete.
     *
     * @return list<string>
     */
    public function missingSetupItems(Business $business): array
    {
        $missing = [];

        foreach ($this->setupItems($business) as $item) {
            if ($item['applicable'] && ! $item['complete']) {
                $missing[] = $item['label'];
            }
        }

        return $missing;
    }

    /**
     * The weighted setup checklist behind the score and missing-items list.
     *
     * @return list<array{label: string, weight: int, applicable: bool, complete: bool}>
     */
    private function setupItems(Business $business): array
    {
        return [
            [
                'label' => 'Decide on a legal structure',
                'weight' => 20,
                'applicable' => true,
                'complete' => ! $business->legal_structure->isUndecided(),
            ],
            [
                'label' => 'Get an EIN (or confirm you do not need one)',
                'weight' => 15,
                'applicable' => true,
                'complete' => $business->has_ein->isYes(),
            ],
            [
                'label' => 'Open a business bank account',
                'weight' => 15,
                'applicable' => true,
                'complete' => $business->has_business_bank,
            ],
            [
                'label' => 'Set up bookkeeping',
                'weight' => 15,
                'applicable' => true,
                'complete' => $business->has_bookkeeping,
            ],
            [
                'label' => 'Resolve sales tax permit status',
                'weight' => 15,
                'applicable' => $business->mayHaveTaxableSales(),
                'complete' => $business->has_sales_tax_permit->isYes(),
            ],
            [
                'label' => 'Set up payroll',
                'weight' => 10,
                'applicable' => $business->employee_count > 0,
                'complete' => $business->has_payroll,
            ],
            [
                'label' => 'Resolve DBA / assumed name status',
                'weight' => 10,
                'applicable' => true,
                'complete' => ! $business->dba_status->isUnsure(),
            ],
        ];
    }
}
