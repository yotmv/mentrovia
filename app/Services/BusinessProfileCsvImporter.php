<?php

namespace App\Services;

use App\Enums\AnnualRevenueRange;
use App\Enums\BusinessOnboardingTrack;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use BackedEnum;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessProfileCsvImporter
{
    private const int MaximumBytes = 131072;

    private const int MaximumColumns = 64;

    private const int MaximumCellBytes = 1024;

    /** @var array<string, string> */
    private const array HeaderMap = [
        'business_name' => 'name',
        'industry' => 'industry',
        'operates_in_texas' => 'operates_in_texas',
        'city' => 'city',
        'county' => 'county',
        'location_type' => 'location_type',
        'address' => 'address',
        'started_on' => 'started_on',
        'legal_structure' => 'legal_structure',
        'dba_status' => 'dba_status',
        'owner_count' => 'owner_count',
        'employee_count' => 'employee_count',
        'uses_contractors' => 'uses_contractors',
        'first_employee_on' => 'first_employee_on',
        'sells_taxable_goods' => 'sells_taxable_goods',
        'sells_taxable_services' => 'sells_taxable_services',
        'has_sales_tax_permit' => 'has_sales_tax_permit',
        'has_ein' => 'has_ein',
        'annual_revenue_range' => 'annual_revenue_range',
        'monthly_revenue_range' => 'monthly_revenue_range',
        'first_sale_on' => 'first_sale_on',
        'has_business_bank' => 'has_business_bank',
        'has_bookkeeping' => 'has_bookkeeping',
        'has_payroll' => 'has_payroll',
        'filing_confidence' => 'filing_confidence',
    ];

    public function __construct(private OnboardingDraftPayload $payloads) {}

    /** @return list<string> */
    public static function headers(): array
    {
        return array_keys(self::HeaderMap);
    }

    /** @return list<string> */
    public static function properties(): array
    {
        return array_values(self::HeaderMap);
    }

    /** @return array<string, string> */
    public static function fieldGuide(): array
    {
        return [
            'operates_in_texas' => 'yes, no',
            'location_type' => implode(', ', array_keys(LocationType::options())),
            'legal_structure' => implode(', ', array_keys(LegalStructure::options())),
            'dba_status' => implode(', ', array_keys(YesNoUnsure::options())),
            'owner_count' => '1–100',
            'employee_count' => '0–5000',
            'uses_contractors' => 'yes, no, true, false, 1, 0',
            'sells_taxable_goods' => implode(', ', array_keys(YesNoUnsure::options())),
            'sells_taxable_services' => implode(', ', array_keys(YesNoUnsure::options())),
            'has_sales_tax_permit' => implode(', ', array_keys(YesNoUnsure::options())),
            'has_ein' => implode(', ', array_keys(YesNoUnsure::options())),
            'annual_revenue_range' => implode(', ', array_keys(AnnualRevenueRange::options())),
            'monthly_revenue_range' => implode(', ', array_keys(MonthlyRevenueRange::options())),
            'has_business_bank' => 'yes, no, true, false, 1, 0',
            'has_bookkeeping' => 'yes, no, true, false, 1, 0',
            'has_payroll' => 'yes, no, true, false, 1, 0',
            'filing_confidence' => implode(', ', array_keys(FilingConfidence::options())),
            'started_on / first_employee_on / first_sale_on' => 'YYYY-MM-DD',
        ];
    }

    /**
     * @return array{
     *     proposals: array<string, bool|int|string>,
     *     warnings: list<string>,
     *     fingerprint: string,
     *     recognized_count: int,
     *     unknown_count: int
     * }
     */
    public function parse(#[\SensitiveParameter] string $contents): array
    {
        if ($contents === ''
            || strlen($contents) > self::MaximumBytes
            || ! mb_check_encoding($contents, 'UTF-8')
            || str_contains($contents, "\0")) {
            throw $this->invalidCsv(__('The CSV must be at most 128 KB of valid UTF-8 text without NUL bytes.'));
        }

        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
        $rows = $this->rows($contents);

        if (count($rows) !== 2) {
            throw $this->invalidCsv(__('The CSV must contain one header row and one nonblank company row.'));
        }

        [$headers, $values] = $rows;

        if ($headers === [] || count($headers) > self::MaximumColumns || count($headers) !== count($values)) {
            throw $this->invalidCsv(__('The CSV header and company row must have the same bounded number of columns.'));
        }

        $normalizedHeaders = array_map(fn (string $header): string => Str::lower(trim($header)), $headers);

        if (count($normalizedHeaders) !== count(array_unique($normalizedHeaders))) {
            throw $this->invalidCsv(__('The CSV contains duplicate headers.'));
        }

        $proposals = [];
        $warnings = [];
        $unknownCount = 0;

        foreach ($normalizedHeaders as $index => $header) {
            $rawValue = trim($values[$index]);

            if (! array_key_exists($header, self::HeaderMap)) {
                $unknownCount++;
                $warnings[] = __('Unknown column ":header" was ignored.', ['header' => $header]);

                continue;
            }

            if ($rawValue === '') {
                continue;
            }

            $property = self::HeaderMap[$header];
            $proposals[$property] = $this->parseValue($property, $rawValue);
        }

        $proposals = $this->payloads->normalize($proposals, BusinessOnboardingTrack::EstablishedCompany);
        $this->payloads->validatePartial($proposals, BusinessOnboardingTrack::EstablishedCompany);
        $proposals = array_filter($proposals, fn (mixed $value): bool => $value !== null);

        return [
            'proposals' => $proposals,
            'warnings' => array_values(array_unique($warnings)),
            'fingerprint' => hash('sha256', $contents),
            'recognized_count' => count($proposals),
            'unknown_count' => $unknownCount,
        ];
    }

    /** @return list<list<string>> */
    private function rows(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw $this->invalidCsv(__('The CSV could not be read.'));
        }

        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $cells = array_map(fn (mixed $cell): string => is_string($cell) ? $cell : '', $row);

            foreach ($cells as $cell) {
                if (strlen($cell) > self::MaximumCellBytes
                    || preg_match('/[\p{Cc}\p{Cf}]/u', $cell) === 1
                    || preg_match('/^\s*[=+\-@]/u', $cell) === 1) {
                    fclose($stream);

                    throw $this->invalidCsv(__('CSV cells may not contain control characters, formulas, or oversized values.'));
                }
            }

            if (collect($cells)->contains(fn (string $cell): bool => trim($cell) !== '')) {
                $rows[] = $cells;
            }

            if (count($rows) > 2) {
                break;
            }
        }

        fclose($stream);

        return $rows;
    }

    private function parseValue(string $property, string $value): bool|int|string
    {
        return match ($property) {
            'owner_count', 'employee_count' => $this->integer($property, $value),
            'uses_contractors', 'has_business_bank', 'has_bookkeeping', 'has_payroll' => $this->boolean($property, $value),
            'started_on', 'first_employee_on', 'first_sale_on' => $this->date($property, $value),
            'location_type' => $this->enum($property, $value, LocationType::cases()),
            'legal_structure' => $this->enum($property, $value, LegalStructure::cases()),
            'dba_status', 'sells_taxable_goods', 'sells_taxable_services', 'has_sales_tax_permit', 'has_ein' => $this->enum($property, $value, YesNoUnsure::cases()),
            'annual_revenue_range' => $this->enum($property, $value, AnnualRevenueRange::cases()),
            'monthly_revenue_range' => $this->enum($property, $value, MonthlyRevenueRange::cases()),
            'filing_confidence' => $this->enum($property, $value, FilingConfidence::cases()),
            'operates_in_texas' => $this->texasAnswer($value),
            default => $value,
        };
    }

    private function integer(string $property, string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw $this->invalidField($property);
        }

        return (int) $value;
    }

    private function boolean(string $property, string $value): bool
    {
        return match ($this->normalized($value)) {
            'yes', 'true', '1' => true,
            'no', 'false', '0' => false,
            default => throw $this->invalidField($property),
        };
    }

    private function texasAnswer(string $value): string
    {
        return match ($this->normalized($value)) {
            'yes', 'true', '1' => 'yes',
            'no', 'false', '0' => 'no',
            default => throw $this->invalidField('operates_in_texas'),
        };
    }

    private function date(string $property, string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            throw $this->invalidField($property);
        }

        return $value;
    }

    /**
     * @param  list<BackedEnum>  $cases
     */
    private function enum(string $property, string $value, array $cases): string
    {
        $needle = $this->normalized($value);

        foreach ($cases as $case) {
            $label = method_exists($case, 'label') ? $case->label() : $case->value;

            if ($needle === $this->normalized((string) $case->value)
                || $needle === $this->normalized((string) $label)) {
                return (string) $case->value;
            }
        }

        throw $this->invalidField($property);
    }

    private function normalized(string $value): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    private function invalidField(string $property): ValidationException
    {
        return $this->invalidCsv(__('The imported value for :field is invalid.', ['field' => $property]));
    }

    private function invalidCsv(string $message): ValidationException
    {
        return ValidationException::withMessages(['csvUpload' => $message]);
    }
}
