<?php

namespace App\Livewire\Business;

use App\Concerns\BusinessIntakeRules;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Services\RecurringTaskGenerator;
use App\Services\StageClassifier;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Intake extends Component
{
    use BusinessIntakeRules;

    public const int LAST_STEP = 5;

    public int $step = 1;

    // Step 1 — Basics
    public ?string $name = null;

    public ?string $desired_name = null;

    public string $dba_status = 'no';

    public string $industry = '';

    public ?string $started_on = null;

    // Step 2 — Location
    public string $operates_in_texas = 'yes';

    public string $city = '';

    public string $county = '';

    public string $location_type = '';

    public ?string $address = null;

    // Step 3 — Structure & people
    public string $legal_structure = '';

    public int $owner_count = 1;

    public int $employee_count = 0;

    public bool $uses_contractors = false;

    public ?string $first_employee_on = null;

    // Step 4 — Taxes & revenue
    public string $sells_taxable_goods = '';

    public string $sells_taxable_services = '';

    public string $has_sales_tax_permit = '';

    public string $has_ein = '';

    public string $annual_revenue_range = '';

    public string $monthly_revenue_range = '';

    public ?string $first_sale_on = null;

    // Step 5 — Setup & confidence
    public bool $has_business_bank = false;

    public bool $has_bookkeeping = false;

    public bool $has_payroll = false;

    public string $filing_confidence = '';

    /**
     * Hydrate from the user's existing business so the wizard doubles as the
     * profile editor.
     */
    public function mount(): void
    {
        $business = Auth::user()->business;

        if ($business === null) {
            return;
        }

        $this->name = $business->name;
        $this->desired_name = $business->desired_name;
        $this->dba_status = $business->dba_status->value;
        $this->industry = $business->industry;
        $this->started_on = $business->started_on?->format('Y-m-d');
        $this->city = $business->city;
        $this->county = $business->county;
        $this->location_type = $business->location_type->value;
        $this->address = $business->address;
        $this->legal_structure = $business->legal_structure->value;
        $this->owner_count = $business->owner_count;
        $this->employee_count = $business->employee_count;
        $this->uses_contractors = $business->uses_contractors;
        $this->first_employee_on = $business->first_employee_on?->format('Y-m-d');
        $this->sells_taxable_goods = $business->sells_taxable_goods->value;
        $this->sells_taxable_services = $business->sells_taxable_services->value;
        $this->has_sales_tax_permit = $business->has_sales_tax_permit->value;
        $this->has_ein = $business->has_ein->value;
        $this->annual_revenue_range = $business->annual_revenue_range->value ?? '';
        $this->monthly_revenue_range = $business->monthly_revenue_range->value ?? '';
        $this->first_sale_on = $business->first_sale_on?->format('Y-m-d');
        $this->has_business_bank = $business->has_business_bank;
        $this->has_bookkeeping = $business->has_bookkeeping;
        $this->has_payroll = $business->has_payroll;
        $this->filing_confidence = $business->filing_confidence->value ?? '';
    }

    public function next(): void
    {
        $this->normalizeEmptyStrings();
        $this->validate($this->stepRules($this->step), $this->intakeMessages());

        if ($this->step < self::LAST_STEP) {
            $this->step++;
        }
    }

    public function back(): void
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function save(StageClassifier $classifier, RecurringTaskGenerator $taskGenerator): void
    {
        $this->normalizeEmptyStrings();
        $this->validate($this->allIntakeRules(), $this->intakeMessages());

        $business = DB::transaction(function () use ($classifier, $taskGenerator): Business {
            $business = Business::updateOrCreate(
                ['user_id' => Auth::id()],
                [
                    'name' => $this->name,
                    'desired_name' => $this->desired_name,
                    'dba_status' => $this->dba_status,
                    'industry' => $this->industry,
                    'started_on' => $this->started_on,
                    'city' => $this->city,
                    'county' => $this->county,
                    'state' => 'TX',
                    'location_type' => $this->location_type,
                    'address' => $this->address,
                    'legal_structure' => $this->legal_structure,
                    'owner_count' => $this->owner_count,
                    'employee_count' => $this->employee_count,
                    'uses_contractors' => $this->uses_contractors,
                    'first_employee_on' => $this->first_employee_on,
                    'sells_taxable_goods' => $this->sells_taxable_goods,
                    'sells_taxable_services' => $this->sells_taxable_services,
                    'has_sales_tax_permit' => $this->has_sales_tax_permit,
                    'has_ein' => $this->has_ein,
                    'annual_revenue_range' => $this->annual_revenue_range,
                    'monthly_revenue_range' => $this->monthly_revenue_range,
                    'first_sale_on' => $this->first_sale_on,
                    'has_business_bank' => $this->has_business_bank,
                    'has_bookkeeping' => $this->has_bookkeeping,
                    'has_payroll' => $this->has_payroll,
                    'filing_confidence' => $this->filing_confidence,
                ],
            );

            $business->stage = $classifier->classify($business);
            $business->save();

            $taskGenerator->generateFor($business);

            return $business;
        });

        Flux::toast(variant: 'success', text: __('Business profile saved. Your stage: :stage', ['stage' => $business->stage->label()]));

        $this->redirectIntended(default: route('dashboard', absolute: false));
    }

    /**
     * Livewire submits cleared optional inputs as empty strings; treat them
     * as null for validation and persistence.
     */
    private function normalizeEmptyStrings(): void
    {
        foreach (['name', 'desired_name', 'started_on', 'address', 'first_employee_on', 'first_sale_on'] as $property) {
            if ($this->{$property} === '') {
                $this->{$property} = null;
            }
        }
    }

    #[Computed]
    public function isBlockedOutsideTexas(): bool
    {
        return $this->operates_in_texas !== 'yes';
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function stepLabels(): array
    {
        return [
            1 => __('Basics'),
            2 => __('Location'),
            3 => __('Structure & people'),
            4 => __('Taxes & revenue'),
            5 => __('Setup & review'),
        ];
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function reviewSummary(): array
    {
        return [
            __('Business name') => $this->name ?: ($this->desired_name ? $this->desired_name.' '.__('(desired)') : '—'),
            __('Industry') => $this->industry ?: '—',
            __('Location') => trim($this->city.', '.$this->county.' County, TX', ', '),
            __('Legal structure') => LegalStructure::tryFrom($this->legal_structure)?->label() ?? '—',
            __('Location type') => LocationType::tryFrom($this->location_type)?->label() ?? '—',
            __('Owners') => (string) $this->owner_count,
            __('Employees') => (string) $this->employee_count,
            __('Sells taxable goods') => YesNoUnsure::tryFrom($this->sells_taxable_goods)?->label() ?? '—',
            __('Sells taxable services') => YesNoUnsure::tryFrom($this->sells_taxable_services)?->label() ?? '—',
            __('Has EIN') => YesNoUnsure::tryFrom($this->has_ein)?->label() ?? '—',
            __('Sales tax permit') => YesNoUnsure::tryFrom($this->has_sales_tax_permit)?->label() ?? '—',
        ];
    }
}
