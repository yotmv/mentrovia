<?php

namespace App\Livewire\Business;

use App\Actions\Business\UpdateBusinessProfileSection;
use App\Enums\AccountRole;
use App\Enums\AnnualRevenueRange;
use App\Enums\BusinessProfileSection;
use App\Enums\FilingConfidence;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use App\Exceptions\BusinessProfileConflictException;
use App\Models\Business;
use App\Models\BusinessProfileVersion;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BusinessProfileSnapshot;
use BackedEnum;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ProfileEditor extends Component
{
    #[Locked]
    public ?string $section = null;

    #[Locked]
    public string $baselineEnvelope = '';

    /** @var array<string, bool|int|string|null> */
    public array $values = [];

    /** @var array<string, array{current: bool|int|string|null, yours: bool|int|string|null}> */
    public array $conflicts = [];

    /** @var array<string, bool|int|string|null> */
    #[Locked]
    public array $pendingPatch = [];

    public string $savedStatus = '';

    protected CurrentAccount $currentAccount;

    public function boot(CurrentAccount $currentAccount): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        $this->currentAccount = $currentAccount;
        $this->currentAccount->resolve($user);
    }

    public function mount(?string $section = null, ?UpdateBusinessProfileSection $updates = null): void
    {
        $business = $this->business();

        if (! $business instanceof Business) {
            $this->redirectRoute('onboarding.welcome', navigate: true);

            return;
        }

        $this->authorize('view', $business);
        $this->section = $section;

        if ($section !== null) {
            abort_unless(BusinessProfileSection::tryFrom($section) instanceof BusinessProfileSection, 404);
            $this->loadSectionValues($updates ?? app(UpdateBusinessProfileSection::class));
        }
    }

    public function save(UpdateBusinessProfileSection $updates): void
    {
        $business = $this->business();
        $section = $this->sectionEnum();
        abort_unless($business instanceof Business && $section instanceof BusinessProfileSection, 404);
        $this->authorize('update', $business);
        $this->resetErrorBag();
        $this->conflicts = [];
        $this->pendingPatch = [];

        try {
            $result = $updates->handle(
                $this->currentAccount->account(),
                $business,
                $this->user(),
                $section,
                $this->values,
                $this->baselineEnvelope,
            );
        } catch (BusinessProfileConflictException $exception) {
            $this->conflicts = $exception->conflicts;
            $this->pendingPatch = $exception->yourPatch;
            $this->addError('profile', __('Some fields changed elsewhere. Review the current and your values, then reload this section to rebase safely.'));

            return;
        }

        $this->loadSectionValues($updates);
        $this->savedStatus = $result['changed']
            ? __('Saved as profile revision :revision.', ['revision' => $result['business']->profile_revision])
            : __('No profile changes to save.');
        Flux::toast($this->savedStatus, variant: 'success');
    }

    public function reloadCurrent(UpdateBusinessProfileSection $updates): void
    {
        $this->conflicts = [];
        $this->pendingPatch = [];
        $this->resetErrorBag();
        $this->loadSectionValues($updates);
        $this->savedStatus = __('Reloaded the latest profile values.');
    }

    public function keepMyEdits(UpdateBusinessProfileSection $updates): void
    {
        $pendingPatch = $this->pendingPatch;
        $this->loadSectionValues($updates);

        foreach ($pendingPatch as $field => $value) {
            if (array_key_exists($field, $this->values)) {
                $this->values[$field] = $value;
            }
        }

        $this->conflicts = [];
        $this->pendingPatch = [];
        $this->resetErrorBag();
        $this->save($updates);
    }

    #[Computed]
    public function business(): ?Business
    {
        return $this->currentAccount->account()->business()->with('profileAnswers')->first();
    }

    #[Computed]
    public function isManager(): bool
    {
        return in_array($this->currentAccount->account()->roleFor($this->user()), [AccountRole::Owner, AccountRole::Admin], true);
    }

    /** @return array<string, array{label: string, type: string, options?: array<string, string>}> */
    #[Computed]
    public function fields(): array
    {
        return collect($this->sectionEnum()?->fields() ?? [])
            ->mapWithKeys(fn (string $field): array => [$field => $this->definition($field)])
            ->all();
    }

    /** @return list<BusinessProfileSection> */
    #[Computed]
    public function sections(): array
    {
        return BusinessProfileSection::cases();
    }

    #[Computed]
    public function currentSection(): ?BusinessProfileSection
    {
        return $this->sectionEnum();
    }

    #[Computed]
    public function latestVersion(): ?BusinessProfileVersion
    {
        return $this->business()?->profileVersions()->latest('revision')->first();
    }

    public function displayValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value instanceof BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : (string) $value->value;
        }

        $enum = match ($field) {
            'dba_status', 'sells_taxable_goods', 'sells_taxable_services', 'has_sales_tax_permit', 'has_ein' => YesNoUnsure::tryFrom((string) $value),
            'location_type' => LocationType::tryFrom((string) $value),
            'legal_structure' => LegalStructure::tryFrom((string) $value),
            'annual_revenue_range' => AnnualRevenueRange::tryFrom((string) $value),
            'monthly_revenue_range' => MonthlyRevenueRange::tryFrom((string) $value),
            'filing_confidence' => FilingConfidence::tryFrom((string) $value),
            default => null,
        };

        return $enum?->label() ?? (string) $value;
    }

    public function render(): View
    {
        return view('livewire.business.profile-editor');
    }

    private function loadSectionValues(UpdateBusinessProfileSection $updates): void
    {
        $business = $this->business();
        $section = $this->sectionEnum();
        abort_unless($business instanceof Business && $section instanceof BusinessProfileSection, 404);
        $facts = app(BusinessProfileSnapshot::class)->businessFacts($business);
        $this->values = collect($section->fields())->mapWithKeys(fn (string $field): array => [$field => $facts[$field] ?? null])->all();
        $this->baselineEnvelope = $updates->baselineEnvelope($business, $section);
    }

    private function sectionEnum(): ?BusinessProfileSection
    {
        return $this->section === null ? null : BusinessProfileSection::tryFrom($this->section);
    }

    /** @return array{label: string, type: string, options?: array<string, string>} */
    private function definition(string $field): array
    {
        $label = match ($field) {
            'name' => __('Business name'), 'desired_name' => __('Desired name'), 'dba_status' => __('DBA status'),
            'industry' => __('Industry'), 'started_on' => __('Started on'), 'city' => __('City'), 'county' => __('County'),
            'state' => __('State'), 'location_type' => __('Operating model'), 'address' => __('Business address'),
            'legal_structure' => __('Legal structure'), 'tax_classification' => __('Tax classification'),
            'owner_count' => __('Owners'), 'employee_count' => __('Employees'), 'uses_contractors' => __('Uses contractors'),
            'first_employee_on' => __('First employee date'), 'sells_taxable_goods' => __('Sells taxable goods'),
            'sells_taxable_services' => __('Sells taxable services'), 'has_sales_tax_permit' => __('Sales tax permit'),
            'has_ein' => __('EIN'), 'annual_revenue_range' => __('Annual revenue'), 'monthly_revenue_range' => __('Monthly revenue'),
            'first_sale_on' => __('First sale date'), 'has_business_bank' => __('Business bank account'),
            'has_bookkeeping' => __('Bookkeeping system'), 'has_payroll' => __('Payroll system'),
            'filing_confidence' => __('Filing confidence'),
            default => __(str_replace('_', ' ', ucfirst($field))),
        };
        $options = match ($field) {
            'dba_status', 'sells_taxable_goods', 'sells_taxable_services', 'has_sales_tax_permit', 'has_ein' => YesNoUnsure::options(),
            'location_type' => LocationType::options(),
            'legal_structure' => LegalStructure::options(),
            'annual_revenue_range' => AnnualRevenueRange::options(),
            'monthly_revenue_range' => MonthlyRevenueRange::options(),
            'filing_confidence' => FilingConfidence::options(),
            default => null,
        };

        return array_filter([
            'label' => $label,
            'type' => $options !== null ? 'select' : match ($field) {
                'uses_contractors', 'has_business_bank', 'has_bookkeeping', 'has_payroll' => 'checkbox',
                'owner_count', 'employee_count' => 'number',
                'started_on', 'first_employee_on', 'first_sale_on' => 'date',
                default => 'text',
            },
            'options' => $options,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
