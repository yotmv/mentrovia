<?php

namespace App\Services;

use App\Enums\AnnualRevenueRange;
use App\Enums\BusinessProfileSection;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BusinessProfilePayload
{
    /** @param array<string, mixed> $values
     * @return array<string, bool|int|string|null>
     */
    public function validateSection(BusinessProfileSection $section, array $values): array
    {
        $normalized = $this->normalize($values, $section->fields());
        Validator::make($normalized, $this->rules($section))->validate();

        return $normalized;
    }

    /** @param array<string, mixed> $values
     * @return array<string, bool|int|string|null>
     */
    public function validateImport(array $values): array
    {
        $allowed = array_merge(...array_map(
            fn (BusinessProfileSection $section): array => $section->fields(),
            BusinessProfileSection::cases(),
        ));
        $normalized = $this->normalize($values, $allowed);
        $rules = collect(BusinessProfileSection::cases())
            ->flatMap(fn (BusinessProfileSection $section): array => $this->rules($section))
            ->only(array_keys($normalized))
            ->map(function (array $fieldRules): array {
                return collect($fieldRules)
                    ->reject(fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'required'))
                    ->prepend('nullable')
                    ->values()
                    ->all();
            })
            ->all();
        Validator::make($normalized, $rules)->validate();

        return $normalized;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(BusinessProfileSection $section): array
    {
        return match ($section) {
            BusinessProfileSection::CompanyBasics => [
                'name' => ['required', 'string', 'max:255'],
                'desired_name' => ['nullable', 'string', 'max:255'],
                'dba_status' => ['required', Rule::enum(YesNoUnsure::class)],
                'industry' => ['required', 'string', 'max:255'],
                'started_on' => ['nullable', 'date_format:Y-m-d'],
            ],
            BusinessProfileSection::LocationStructure => [
                'city' => ['required', 'string', 'max:255'],
                'county' => ['required', 'string', 'max:255'],
                'state' => ['required', 'in:TX'],
                'location_type' => ['required', Rule::enum(LocationType::class)],
                'address' => ['nullable', 'string', 'max:255'],
                'legal_structure' => ['required', Rule::enum(LegalStructure::class)],
                'tax_classification' => ['nullable', 'string', 'max:100'],
            ],
            BusinessProfileSection::PeopleObligations => [
                'owner_count' => ['required', 'integer', 'min:1', 'max:100'],
                'employee_count' => ['required', 'integer', 'min:0', 'max:5000'],
                'uses_contractors' => ['required', 'boolean'],
                'first_employee_on' => ['nullable', 'date_format:Y-m-d'],
                'sells_taxable_goods' => ['required', Rule::enum(YesNoUnsure::class)],
                'sells_taxable_services' => ['required', Rule::enum(YesNoUnsure::class)],
                'has_sales_tax_permit' => ['required', Rule::enum(YesNoUnsure::class)],
                'has_ein' => ['required', Rule::enum(YesNoUnsure::class)],
            ],
            BusinessProfileSection::OperationsReadiness => [
                'annual_revenue_range' => ['required', Rule::enum(AnnualRevenueRange::class)],
                'monthly_revenue_range' => ['required', Rule::enum(MonthlyRevenueRange::class)],
                'first_sale_on' => ['nullable', 'date_format:Y-m-d'],
                'has_business_bank' => ['required', 'boolean'],
                'has_bookkeeping' => ['required', 'boolean'],
                'has_payroll' => ['required', 'boolean'],
                'filing_confidence' => ['required', Rule::enum(FilingConfidence::class)],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowedFields
     * @return array<string, bool|int|string|null>
     */
    private function normalize(array $values, array $allowedFields): array
    {
        $unknownFields = array_values(array_diff(array_keys($values), $allowedFields));

        if ($unknownFields !== []) {
            throw ValidationException::withMessages([
                $unknownFields[0] => __('This field is not part of the selected profile section.'),
            ]);
        }

        $normalized = [];

        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $value = $values[$field];

            if (is_array($value) || is_object($value) || is_resource($value)) {
                throw ValidationException::withMessages([$field => __('This profile value is invalid.')]);
            }

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            if (in_array($field, ['owner_count', 'employee_count'], true) && is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
                $value = (int) $value;
            }

            if (in_array($field, ['uses_contractors', 'has_business_bank', 'has_bookkeeping', 'has_payroll'], true)) {
                $boolean = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($boolean === null) {
                    throw ValidationException::withMessages([$field => __('This profile value must be yes or no.')]);
                }

                $value = $boolean;
            }

            if ($field === 'state' && is_string($value)) {
                $value = strtoupper($value);
            }

            if (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null) {
                throw ValidationException::withMessages([$field => __('This profile value is invalid.')]);
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }
}
