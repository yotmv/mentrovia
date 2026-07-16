<?php

namespace App\Livewire\Business;

use App\Actions\Business\FinalizeBusinessIntake;
use App\Actions\Business\SaveOnboardingDraft;
use App\Concerns\BusinessIntakeRules;
use App\Enums\BusinessOnboardingTrack;
use App\Enums\LegalStructure;
use App\Enums\LocationType;
use App\Enums\YesNoUnsure;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BusinessProfileCsvImporter;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class Intake extends Component
{
    use BusinessIntakeRules, WithFileUploads;

    #[Locked]
    public int $step = 1;

    public string $returnTo = 'plan-ready';

    #[Locked]
    public string $track = 'new_company';

    #[Locked]
    public ?int $draftRevision = null;

    #[Locked]
    public ?int $existingBusinessId = null;

    #[Locked]
    public ?string $existingBusinessVersion = null;

    public string $savedStatus = '';

    public ?TemporaryUploadedFile $csvUpload = null;

    /** @var array<string, array{label: string, current: bool|int|string|null, imported: bool|int|string}> */
    public array $importPreview = [];

    /** @var array<string, bool> */
    public array $importSelections = [];

    /** @var list<string> */
    public array $importWarnings = [];

    public string $importEnvelope = '';

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

    protected CurrentAccount $currentAccount;

    public function boot(CurrentAccount $currentAccount): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $this->currentAccount = $currentAccount;
        $this->currentAccount->resolve($user);
    }

    public function mount(?string $returnTo = null): void
    {
        $business = $this->currentAccount->account()->business;

        if ($returnTo === 'business') {
            $this->returnTo = 'business';
        }

        if ($business === null) {
            $draft = $this->currentAccount->account()->onboardingDraft()->first();

            if ($draft instanceof OnboardingDraft) {
                $this->track = $draft->track->value;
                $this->step = min(max(1, $draft->current_step), $draft->track->stepCount());
                $this->draftRevision = $draft->revision;
                $this->hydrateValues($draft->payload);
            }

            return;
        }

        $this->redirectRoute('business.edit', navigate: true);
    }

    public function next(SaveOnboardingDraft $saveDraft): void
    {
        $track = $this->validTrackForCurrentStep();
        $texasStep = $track === BusinessOnboardingTrack::EstablishedCompany ? 1 : 2;

        if ($this->step === $texasStep && $this->operates_in_texas !== 'yes') {
            $this->normalizeEmptyStrings();

            if ($this->existingBusinessId === null) {
                $draft = $saveDraft->handle(
                    $this->currentAccount->account(),
                    $this->user(),
                    $track,
                    $this->step,
                    $this->formValues(),
                    $this->draftRevision,
                );
                $this->draftRevision = $draft->revision;
            }

            $this->redirectRoute('business.not-supported', navigate: true);

            return;
        }

        $this->normalizeEmptyStrings();
        $this->validate($this->intakeRulesFor($track, $this->step), $this->intakeMessages());

        if ($this->step < $track->stepCount()) {
            $nextStep = $this->step + 1;

            if ($this->existingBusinessId !== null) {
                $this->step = $nextStep;
                $this->savedStatus = __('Changes are not saved until you save the profile.');

                return;
            }

            $draft = $saveDraft->handle(
                $this->currentAccount->account(),
                $this->user(),
                $track,
                $nextStep,
                $this->formValues(),
                $this->draftRevision,
                $this->step,
            );
            $this->draftRevision = $draft->revision;
            $this->step = $nextStep;
            $this->savedStatus = __('Saved :time', ['time' => now()->format('g:i A')]);
        }
    }

    public function back(): void
    {
        $this->validTrackForCurrentStep();

        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function saveAndExit(SaveOnboardingDraft $saveDraft): void
    {
        $this->validTrackForCurrentStep();

        if ($this->existingBusinessId !== null) {
            $this->redirectRoute('business.overview', navigate: true);

            return;
        }

        $this->normalizeEmptyStrings();
        $draft = $saveDraft->handle(
            $this->currentAccount->account(),
            $this->user(),
            $this->trackEnum(),
            $this->step,
            $this->formValues(),
            $this->draftRevision,
        );
        $this->draftRevision = $draft->revision;
        $this->redirectRoute('onboarding.welcome', navigate: true);
    }

    public function save(
        SaveOnboardingDraft $saveDraft,
        FinalizeBusinessIntake $finalize,
    ): void {
        $track = $this->validTrackForCurrentStep();

        if ($this->step !== $track->stepCount()) {
            throw ValidationException::withMessages([
                'step' => __('Complete each company profile step before saving.'),
            ]);
        }

        $this->normalizeEmptyStrings();
        $this->validate($this->allIntakeRulesFor($track), $this->intakeMessages());

        if ($this->existingBusinessId === null && $this->draftRevision === null) {
            $draft = $saveDraft->handle(
                $this->currentAccount->account(),
                $this->user(),
                $track,
                $this->step,
                $this->formValues(),
                null,
            );
            $this->draftRevision = $draft->revision;
        }

        $result = $finalize->handle(
            $this->currentAccount->account(),
            $this->user(),
            $track,
            $this->formValues(),
            $this->draftRevision,
            $this->existingBusinessId,
            $this->existingBusinessVersion,
        );
        $business = $result['business'];

        if ($result['created']) {
            session()->flash('onboarding.finalized_track', $track->value);
        }

        Flux::toast(variant: 'success', text: __('Business profile saved. Your stage: :stage', ['stage' => $business->stage->label()]));

        $destination = $result['created'] && $this->returnTo !== 'business'
            ? route('onboarding.plan-ready', absolute: false)
            : route('business.overview', absolute: false);

        $this->redirectIntended(default: $destination);
    }

    public function previewCsv(BusinessProfileCsvImporter $importer): void
    {
        $track = $this->validTrackForCurrentStep();
        abort_unless($this->existingBusinessId === null && $track === BusinessOnboardingTrack::EstablishedCompany, 404);
        $this->clearImportState();

        try {
            $this->validate([
                'csvUpload' => [
                    'required',
                    'file',
                    'max:128',
                    'extensions:csv',
                    'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                ],
            ]);
            $path = $this->csvUpload?->getRealPath();
            $contents = is_string($path) ? file_get_contents($path) : false;

            if (! is_string($contents)) {
                throw ValidationException::withMessages(['csvUpload' => __('The CSV could not be read.')]);
            }

            $parsed = $importer->parse($contents);
            foreach ($parsed['proposals'] as $property => $imported) {
                $this->importPreview[$property] = [
                    'label' => $this->importLabel($property),
                    'current' => $this->{$property},
                    'imported' => $imported,
                ];
                $this->importSelections[$property] = true;
            }

            $this->importWarnings = $parsed['warnings'];
            $this->importEnvelope = Crypt::encryptString(json_encode([
                'account_id' => $this->currentAccount->id(),
                'track' => $track->value,
                'draft_revision' => $this->draftRevision,
                'proposals' => $parsed['proposals'],
                'fingerprint' => $parsed['fingerprint'],
                'recognized_count' => $parsed['recognized_count'],
                'unknown_count' => $parsed['unknown_count'],
            ], JSON_THROW_ON_ERROR));
        } finally {
            $this->csvUpload?->delete();
            $this->reset('csvUpload');
        }
    }

    public function applyCsv(SaveOnboardingDraft $saveDraft): void
    {
        $track = $this->validTrackForCurrentStep();
        abort_unless($this->existingBusinessId === null && $track === BusinessOnboardingTrack::EstablishedCompany, 404);

        try {
            $envelope = json_decode(Crypt::decryptString($this->importEnvelope), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['csvUpload' => __('The import preview is no longer valid.')]);
        }

        if (! is_array($envelope)
            || ($envelope['account_id'] ?? null) !== $this->currentAccount->id()
            || ($envelope['track'] ?? null) !== $track->value
            || ($envelope['draft_revision'] ?? null) !== $this->draftRevision
            || ! is_array($envelope['proposals'] ?? null)) {
            throw ValidationException::withMessages(['csvUpload' => __('The import preview is stale. Upload the CSV again.')]);
        }

        $allowedProperties = array_fill_keys(BusinessProfileCsvImporter::properties(), true);
        $proposals = $envelope['proposals'];
        $pendingValues = $this->formValues();

        foreach ($proposals as $property => $value) {
            if (! is_string($property)
                || ! array_key_exists($property, $allowedProperties)
                || (! is_bool($value) && ! is_int($value) && ! is_string($value))) {
                throw ValidationException::withMessages(['csvUpload' => __('The import preview contains invalid fields. Upload the CSV again.')]);
            }

            if (($this->importSelections[$property] ?? false) === true) {
                $pendingValues[$property] = $value;
            }
        }

        if (($pendingValues['employee_count'] ?? 0) === 0) {
            $pendingValues['first_employee_on'] = null;
            $pendingValues['has_payroll'] = false;
        }

        $draft = $saveDraft->handle(
            $this->currentAccount->account(),
            $this->user(),
            $track,
            $this->step,
            $pendingValues,
            $this->draftRevision,
        );

        $this->hydrateValues($draft->payload);
        $this->draftRevision = $draft->revision;
        $this->savedStatus = __('Import applied and saved :time', ['time' => now()->format('g:i A')]);
        $this->clearImportState();
    }

    public function clearImportPreview(): void
    {
        $this->validTrackForCurrentStep();
        $this->clearImportState();
    }

    private function clearImportState(): void
    {
        $this->reset('importPreview', 'importSelections', 'importWarnings', 'importEnvelope');
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

        if ($this->trackEnum() === BusinessOnboardingTrack::EstablishedCompany) {
            $this->desired_name = null;
            $this->normalizeEstablishedPeople();
        }
    }

    private function normalizeEstablishedPeople(): void
    {
        if ($this->employee_count === 0) {
            $this->first_employee_on = null;
            $this->has_payroll = false;
        }
    }

    /** @return array<string, bool|int|string|null> */
    private function formValues(): array
    {
        return [
            'name' => $this->name,
            'desired_name' => $this->desired_name,
            'dba_status' => $this->dba_status,
            'industry' => $this->industry,
            'started_on' => $this->started_on,
            'operates_in_texas' => $this->operates_in_texas,
            'city' => $this->city,
            'county' => $this->county,
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
        ];
    }

    /** @param array<string, mixed> $values */
    private function hydrateValues(array $values): void
    {
        foreach ($this->formValues() as $property => $default) {
            if (! array_key_exists($property, $values)) {
                continue;
            }

            $value = $values[$property];

            if (is_bool($default)) {
                $this->{$property} = (bool) $value;
            } elseif (is_int($default)) {
                $this->{$property} = (int) $value;
            } elseif ($default === null) {
                $this->{$property} = is_string($value) ? $value : null;
            } else {
                $this->{$property} = is_string($value) ? $value : '';
            }
        }
    }

    private function trackEnum(): BusinessOnboardingTrack
    {
        return BusinessOnboardingTrack::from($this->track);
    }

    private function validTrackForCurrentStep(): BusinessOnboardingTrack
    {
        $track = $this->trackEnum();

        if ($this->step < 1 || $this->step > $track->stepCount()) {
            throw ValidationException::withMessages([
                'step' => __('The company profile step is invalid. Reload and try again.'),
            ]);
        }

        return $track;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function importLabel(string $property): string
    {
        return match ($property) {
            'name' => __('Business name'),
            'dba_status' => __('DBA status'),
            'operates_in_texas' => __('Operates in Texas'),
            'location_type' => __('Operating model'),
            'legal_structure' => __('Legal structure'),
            'owner_count' => __('Owner count'),
            'employee_count' => __('Employee count'),
            'uses_contractors' => __('Uses contractors'),
            'first_employee_on' => __('First employee date'),
            'sells_taxable_goods' => __('Taxable goods'),
            'sells_taxable_services' => __('Taxable services'),
            'has_sales_tax_permit' => __('Sales tax permit'),
            'has_ein' => __('EIN'),
            'annual_revenue_range' => __('Annual revenue'),
            'monthly_revenue_range' => __('Monthly revenue'),
            'first_sale_on' => __('First sale date'),
            'has_business_bank' => __('Business bank account'),
            'has_bookkeeping' => __('Bookkeeping'),
            'has_payroll' => __('Payroll provider'),
            'filing_confidence' => __('Filing confidence'),
            default => __(str_replace('_', ' ', ucfirst($property))),
        };
    }

    #[Computed]
    public function isBlockedOutsideTexas(): bool
    {
        return $this->operates_in_texas !== 'yes';
    }

    #[Computed]
    public function lastStep(): int
    {
        return $this->trackEnum()->stepCount();
    }

    #[Computed]
    public function isEstablishedTrack(): bool
    {
        return $this->trackEnum() === BusinessOnboardingTrack::EstablishedCompany;
    }

    /** @return array<string, string> */
    #[Computed]
    public function csvFieldGuide(): array
    {
        return BusinessProfileCsvImporter::fieldGuide();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function stepLabels(): array
    {
        if ($this->isEstablishedTrack()) {
            return [
                1 => __('Company & location'),
                2 => __('People & obligations'),
                3 => __('Operating baseline & review'),
            ];
        }

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
