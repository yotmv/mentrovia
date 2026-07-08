<?php

namespace App\Concerns;

use App\Enums\AnnualRevenueRange;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use Illuminate\Validation\Rule;

trait BusinessIntakeRules
{
    /**
     * Validation rules for a single intake wizard step.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function stepRules(int $step): array
    {
        return match ($step) {
            1 => [
                'name' => ['nullable', 'string', 'max:255', 'required_without:desired_name'],
                'desired_name' => ['nullable', 'string', 'max:255', 'required_without:name'],
                'dba_status' => ['required', Rule::enum(YesNoUnsure::class)],
                'industry' => ['required', 'string', 'max:255'],
                'started_on' => ['nullable', 'date'],
            ],
            2 => [
                'operates_in_texas' => ['required', 'in:yes'],
                'city' => ['required', 'string', 'max:255'],
                'county' => ['required', 'string', 'max:255'],
                'location_type' => ['required', Rule::enum(LocationType::class)],
                'address' => ['nullable', 'string', 'max:255'],
            ],
            3 => [
                'legal_structure' => ['required', Rule::enum(LegalStructure::class)],
                'owner_count' => ['required', 'integer', 'min:1', 'max:100'],
                'employee_count' => ['required', 'integer', 'min:0', 'max:5000'],
                'uses_contractors' => ['boolean'],
                'first_employee_on' => ['nullable', 'date'],
            ],
            4 => [
                'sells_taxable_goods' => ['required', Rule::enum(YesNoUnsure::class)],
                'sells_taxable_services' => ['required', Rule::enum(YesNoUnsure::class)],
                'has_sales_tax_permit' => ['required', Rule::enum(YesNoUnsure::class)],
                'has_ein' => ['required', Rule::enum(YesNoUnsure::class)],
                'annual_revenue_range' => ['required', Rule::enum(AnnualRevenueRange::class)],
                'monthly_revenue_range' => ['required', Rule::enum(MonthlyRevenueRange::class)],
                'first_sale_on' => ['nullable', 'date'],
            ],
            5 => [
                'has_business_bank' => ['boolean'],
                'has_bookkeeping' => ['boolean'],
                'has_payroll' => ['boolean'],
                'filing_confidence' => ['required', Rule::enum(FilingConfidence::class)],
            ],
            default => [],
        };
    }

    /**
     * Combined rules for every intake step, used on final submit.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function allIntakeRules(): array
    {
        return array_merge(...array_map(fn (int $step): array => $this->stepRules($step), range(1, 5)));
    }

    /**
     * Human-friendly messages for intake validation.
     *
     * @return array<string, string>
     */
    protected function intakeMessages(): array
    {
        return [
            'operates_in_texas.in' => __('Mentrovia currently supports Texas businesses only. Support for other states is planned.'),
            'name.required_without' => __('Enter your business name, or the name you would like to use.'),
            'desired_name.required_without' => __('Enter your business name, or the name you would like to use.'),
        ];
    }
}
