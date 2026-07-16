<?php

namespace App\Services;

use App\Models\Business;
use BackedEnum;
use Carbon\CarbonInterface;

final class BusinessProfileSnapshot
{
    public const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const array CORE_FIELDS = [
        'name', 'desired_name', 'dba_status', 'stage', 'legal_structure', 'tax_classification',
        'industry', 'city', 'county', 'state', 'location_type', 'address', 'owner_count',
        'employee_count', 'uses_contractors', 'sells_taxable_goods', 'sells_taxable_services',
        'has_sales_tax_permit', 'has_ein', 'has_business_bank', 'has_bookkeeping', 'has_payroll',
        'annual_revenue_range', 'monthly_revenue_range', 'started_on', 'first_sale_on',
        'first_employee_on', 'filing_confidence',
    ];

    /**
     * @return array{schema_version: int, business: array<string, bool|int|string|null>, profile_answers: list<array{question_key: string, answer_value: string|null, confidence: string|null}>}
     */
    public function capture(Business $business): array
    {
        $business->loadMissing('profileAnswers');
        $profileAnswers = [];

        foreach ($business->profileAnswers->sortBy('question_key', SORT_STRING) as $answer) {
            $profileAnswers[] = [
                'question_key' => $answer->question_key,
                'answer_value' => $answer->answer_value,
                'confidence' => $answer->confidence,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'business' => $this->businessFacts($business),
            'profile_answers' => $profileAnswers,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    public function businessFacts(Business $business): array
    {
        $facts = [];

        foreach (self::CORE_FIELDS as $field) {
            $facts[$field] = $this->scalar($business->{$field});
        }

        ksort($facts, SORT_STRING);

        return $facts;
    }

    private function scalar(mixed $value): bool|int|string|null
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_bool($value), is_int($value), is_string($value), $value === null => $value,
            default => (string) $value,
        };
    }
}
