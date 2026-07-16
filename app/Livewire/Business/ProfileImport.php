<?php

namespace App\Livewire\Business;

use App\Actions\Business\ImportBusinessProfile;
use App\Enums\AccountRole;
use App\Enums\BusinessProfileSection;
use App\Models\Business;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BusinessProfileCsvImporter;
use App\Services\BusinessProfileFingerprint;
use App\Services\BusinessProfileSnapshot;
use App\Services\BusinessProfileValuePresenter;
use App\Services\BusinessProfileVersionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class ProfileImport extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $csvUpload = null;

    /** @var array<string, array{label: string, section: string, current: mixed, imported: mixed, result: mixed}> */
    public array $preview = [];

    /** @var array<string, bool> */
    public array $selections = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var array<string, bool|int|string|null> */
    #[Locked]
    public array $currentValues = [];

    #[Locked]
    public string $previewEnvelope = '';

    protected CurrentAccount $currentAccount;

    protected BusinessProfileValuePresenter $valuePresenter;

    public function boot(CurrentAccount $currentAccount, BusinessProfileValuePresenter $valuePresenter): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);
        $this->currentAccount = $currentAccount;
        $this->valuePresenter = $valuePresenter;
        $this->currentAccount->resolve($user);
    }

    public function mount(): void
    {
        $business = $this->business();
        abort_unless($business instanceof Business, 404);
        $this->authorize('view', $business);
        abort_unless(in_array($this->currentAccount->account()->roleFor($this->user()), [AccountRole::Owner, AccountRole::Admin], true), 403);
    }

    public function previewCsv(
        BusinessProfileCsvImporter $importer,
        BusinessProfileSnapshot $snapshots,
        BusinessProfileVersionService $versions,
        BusinessProfileFingerprint $fingerprints,
    ): void {
        $business = $this->business();
        abort_unless($business instanceof Business, 404);
        $this->resetPreview();

        try {
            $this->validate([
                'csvUpload' => [
                    'required', 'file', 'max:128', 'extensions:csv',
                    'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                ],
            ]);
            $path = $this->csvUpload?->getRealPath();
            $contents = is_string($path) ? file_get_contents($path) : false;

            if (! is_string($contents)) {
                throw ValidationException::withMessages(['csvUpload' => __('The CSV could not be read.')]);
            }

            $parsed = $importer->parse($contents);
            $current = $snapshots->businessFacts($business);
            $proposals = collect($parsed['proposals'])
                ->only(collect(BusinessProfileSection::cases())->flatMap->fields()->all())
                ->all();
            $this->currentValues = $current;

            foreach ($proposals as $field => $imported) {
                $section = BusinessProfileSection::forField($field);

                if (! $section instanceof BusinessProfileSection) {
                    continue;
                }

                $this->preview[$field] = [
                    'label' => str($field)->replace('_', ' ')->title()->toString(),
                    'section' => $section->value,
                    'current' => $current[$field] ?? null,
                    'imported' => $imported,
                    'result' => $imported,
                ];
                $this->selections[$field] = true;
            }

            $this->warnings = $parsed['warnings'];
            $this->previewEnvelope = Crypt::encryptString(json_encode([
                'schema_version' => BusinessProfileSnapshot::SCHEMA_VERSION,
                'account_id' => $business->account_id,
                'business_id' => $business->id,
                'profile_revision' => $business->profile_revision,
                'profile_fingerprint' => $versions->issue($business),
                'proposals' => $proposals,
                'source_fingerprint' => $fingerprints->make(['csv_digest' => $parsed['fingerprint']]),
                'recognized_count' => count($proposals),
                'unknown_count' => $parsed['unknown_count'],
            ], JSON_THROW_ON_ERROR));
        } finally {
            $this->csvUpload?->delete();
            $this->reset('csvUpload');
        }
    }

    public function apply(ImportBusinessProfile $import): void
    {
        $business = $this->business();
        abort_unless($business instanceof Business, 404);

        if (! $this->hasApplicableChanges()) {
            $this->addError(
                'csvUpload',
                $this->hasInvariantBlockedSelections()
                    ? __('Employee consistency rules prevent the selected payroll or first employee values while employee count remains zero.')
                    : __('This CSV already matches the current profile.'),
            );

            return;
        }

        $result = $import->handle(
            $this->currentAccount->account(),
            $business,
            $this->user(),
            $this->previewEnvelope,
            $this->selections,
        );
        $this->resetPreview();
        Flux::toast(__('Imported :count profile fields in revision :revision.', [
            'count' => count($result['changed_fields']),
            'revision' => $result['business']->profile_revision,
        ]), variant: 'success');
    }

    public function clearPreview(): void
    {
        $this->resetPreview();
    }

    public function displayValue(string $field, mixed $value): string
    {
        return $this->valuePresenter->present($field, $value);
    }

    /** @return array<string, array{label: string, section: string, current: mixed, imported: mixed, result: mixed, derived: bool}> */
    #[Computed]
    public function previewRows(): array
    {
        $rows = collect($this->preview)->map(function (array $row, string $field): array {
            return [
                ...$row,
                'result' => ($this->selections[$field] ?? false) ? $row['imported'] : $row['current'],
                'derived' => false,
            ];
        })->all();
        $resultingEmployeeCount = (int) ($rows['employee_count']['result'] ?? $this->currentValues['employee_count'] ?? 0);

        if ($resultingEmployeeCount === 0) {
            foreach ([
                'first_employee_on' => null,
                'has_payroll' => false,
            ] as $field => $result) {
                $section = BusinessProfileSection::forField($field) ?? BusinessProfileSection::OperationsReadiness;
                $existing = $rows[$field] ?? null;

                if ($existing === null && ($this->currentValues[$field] ?? null) === $result) {
                    continue;
                }

                $rows[$field] = [
                    'label' => str($field)->replace('_', ' ')->title()->toString(),
                    'section' => $section->value,
                    'current' => $this->currentValues[$field] ?? null,
                    'imported' => $existing['imported'] ?? null,
                    'result' => $result,
                    'derived' => true,
                ];
            }
        }

        return $rows;
    }

    #[Computed]
    public function hasApplicableChanges(): bool
    {
        return collect($this->previewRows())
            ->contains(fn (array $row): bool => $row['current'] !== $row['result']);
    }

    #[Computed]
    public function hasInvariantBlockedSelections(): bool
    {
        return collect($this->previewRows())
            ->contains(fn (array $row, string $field): bool => $row['derived']
                && array_key_exists($field, $this->preview)
                && ($this->selections[$field] ?? false)
                && $row['imported'] !== $row['result']);
    }

    /** @return list<string> */
    #[Computed]
    public function derivedChanges(): array
    {
        return array_values(collect($this->previewRows())
            ->filter(fn (array $row): bool => $row['derived'] && $row['current'] !== $row['result'])
            ->map(fn (array $row, string $field): string => __(':field will change to :value to keep employee facts consistent.', [
                'field' => $row['label'],
                'value' => $this->displayValue($field, $row['result']),
            ]))
            ->values()
            ->all());
    }

    public function render(): View
    {
        return view('livewire.business.profile-import');
    }

    private function business(): ?Business
    {
        return $this->currentAccount->account()->business()->with('profileAnswers')->first();
    }

    private function resetPreview(): void
    {
        $this->preview = [];
        $this->selections = [];
        $this->warnings = [];
        $this->currentValues = [];
        $this->previewEnvelope = '';
        $this->resetErrorBag();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
