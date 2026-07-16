<?php

namespace App\Services;

use App\Concerns\BusinessIntakeRules;
use App\Enums\BusinessOnboardingTrack;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OnboardingDraftPayload
{
    use BusinessIntakeRules;

    public const int SCHEMA_VERSION = 1;

    private const int MaximumPlaintextBytes = 32768;

    /** @var list<string> */
    private const array AllowedFields = [
        'name', 'desired_name', 'dba_status', 'industry', 'started_on',
        'operates_in_texas', 'city', 'county', 'location_type', 'address',
        'legal_structure', 'owner_count', 'employee_count', 'uses_contractors',
        'first_employee_on', 'sells_taxable_goods', 'sells_taxable_services',
        'has_sales_tax_permit', 'has_ein', 'annual_revenue_range',
        'monthly_revenue_range', 'first_sale_on', 'has_business_bank',
        'has_bookkeeping', 'has_payroll', 'filing_confidence',
    ];

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, bool|int|string|null>
     */
    public function normalize(array $values, BusinessOnboardingTrack $track): array
    {
        $normalized = [];

        foreach (self::AllowedFields as $field) {
            if (! array_key_exists($field, $values)) {
                continue;
            }

            $value = $values[$field];

            if (is_array($value) || is_object($value) || is_resource($value)) {
                throw ValidationException::withMessages([$field => __('This answer has an invalid value.')]);
            }

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }

            if (in_array($field, ['owner_count', 'employee_count'], true)
                && is_string($value)
                && preg_match('/^-?\d+$/D', $value) === 1) {
                $value = (int) $value;
            }

            if (in_array($field, ['uses_contractors', 'has_business_bank', 'has_bookkeeping', 'has_payroll'], true)) {
                $boolean = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                if ($value !== null && $boolean === null) {
                    throw ValidationException::withMessages([$field => __('This answer must be yes or no.')]);
                }

                $value = $boolean;
            }

            if (! is_bool($value) && ! is_int($value) && ! is_string($value) && $value !== null) {
                throw ValidationException::withMessages([$field => __('This answer has an invalid value.')]);
            }

            $normalized[$field] = $value;
        }

        if ($track === BusinessOnboardingTrack::EstablishedCompany) {
            $normalized['desired_name'] = null;
        }

        if (strlen(json_encode($normalized, JSON_THROW_ON_ERROR)) > self::MaximumPlaintextBytes) {
            throw ValidationException::withMessages(['draft' => __('The saved company profile is too large.')]);
        }

        return $normalized;
    }

    /** @param array<string, bool|int|string|null> $payload */
    public function validateStep(array $payload, BusinessOnboardingTrack $track, int $step): void
    {
        Validator::make($payload, $this->intakeRulesFor($track, $step), $this->intakeMessages())->validate();
    }

    /** @param array<string, bool|int|string|null> $payload */
    public function validateComplete(array $payload, BusinessOnboardingTrack $track): void
    {
        Validator::make($payload, $this->allIntakeRulesFor($track), $this->intakeMessages())->validate();
    }

    /** @param array<string, bool|int|string|null> $payload */
    public function validatePartial(array $payload, BusinessOnboardingTrack $track): void
    {
        $rules = collect($this->allIntakeRulesFor($track))->map(function (array $fieldRules): array {
            $optionalRules = collect($fieldRules)
                ->reject(fn (mixed $rule): bool => is_string($rule) && str_starts_with($rule, 'required'))
                ->map(fn (mixed $rule): mixed => $rule === 'in:yes' ? 'in:yes,no' : $rule)
                ->values()
                ->all();

            array_unshift($optionalRules, 'nullable');

            return $optionalRules;
        })->all();

        Validator::make($payload, $rules, $this->intakeMessages())->validate();
    }
}
