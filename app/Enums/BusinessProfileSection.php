<?php

namespace App\Enums;

enum BusinessProfileSection: string
{
    case CompanyBasics = 'company_basics';
    case LocationStructure = 'location_structure';
    case PeopleObligations = 'people_obligations';
    case OperationsReadiness = 'operations_readiness';

    public function label(): string
    {
        return match ($this) {
            self::CompanyBasics => 'Company basics',
            self::LocationStructure => 'Location & structure',
            self::PeopleObligations => 'People & obligations',
            self::OperationsReadiness => 'Operations & readiness',
        };
    }

    /** @return list<string> */
    public function fields(): array
    {
        return match ($this) {
            self::CompanyBasics => ['name', 'desired_name', 'dba_status', 'industry', 'started_on'],
            self::LocationStructure => ['city', 'county', 'state', 'location_type', 'address', 'legal_structure', 'tax_classification'],
            self::PeopleObligations => [
                'owner_count', 'employee_count', 'uses_contractors', 'first_employee_on',
                'sells_taxable_goods', 'sells_taxable_services', 'has_sales_tax_permit', 'has_ein',
            ],
            self::OperationsReadiness => [
                'annual_revenue_range', 'monthly_revenue_range', 'first_sale_on',
                'has_business_bank', 'has_bookkeeping', 'has_payroll', 'filing_confidence',
            ],
        };
    }

    public static function forField(string $field): ?self
    {
        foreach (self::cases() as $section) {
            if (in_array($field, $section->fields(), true)) {
                return $section;
            }
        }

        return null;
    }
}
