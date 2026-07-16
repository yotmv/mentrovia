<?php

namespace App\Services;

use App\Enums\AnnualRevenueRange;
use App\Enums\BusinessStage;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use BackedEnum;
use Carbon\CarbonInterface;
use DateTimeImmutable;

final class BusinessProfileValuePresenter
{
    /** @var list<string> */
    private const array DATE_FIELDS = ['started_on', 'first_sale_on', 'first_employee_on'];

    public function present(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if ($value instanceof CarbonInterface) {
            return $value->format('M j, Y');
        }

        if ($value instanceof BackedEnum && method_exists($value, 'label')) {
            return $value->label();
        }

        if (is_string($value) && in_array($field, self::DATE_FIELDS, true)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) {
                return $date->format('M j, Y');
            }
        }

        $enum = is_string($value) ? match ($field) {
            'stage' => BusinessStage::tryFrom($value),
            'legal_structure' => LegalStructure::tryFrom($value),
            'location_type' => LocationType::tryFrom($value),
            'annual_revenue_range' => AnnualRevenueRange::tryFrom($value),
            'monthly_revenue_range' => MonthlyRevenueRange::tryFrom($value),
            'filing_confidence' => FilingConfidence::tryFrom($value),
            'dba_status', 'sells_taxable_goods', 'sells_taxable_services', 'has_sales_tax_permit', 'has_ein' => YesNoUnsure::tryFrom($value),
            default => null,
        } : null;

        return $enum?->label() ?? (string) $value;
    }
}
