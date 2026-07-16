<?php

namespace App\Livewire\Settings;

use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Models\Account;
use App\Models\AiAccountSetting;
use App\Models\AiModelPreference;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use App\Services\Ai\AiAuditLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Component;

class Ai extends Component
{
    private CurrentAccount $currentAccount;

    public bool $paidAiEnabled = true;

    public bool $hostedAiEnabled = true;

    public bool $byokEnabled = false;

    public ?float $monthlyUsdLimit = null;

    public ?float $perOperationUsdLimit = null;

    public int $maxConcurrency = 1;

    /** @var array<string, string> */
    public array $modelModes = [];

    /** @var array<string, array<int, string>> */
    public array $models = [];

    /** @var array<string, string> */
    public array $newModels = [];

    public ?string $credentialLastFour = null;

    public function boot(CurrentAccount $currentAccount): void
    {
        $this->currentAccount = $currentAccount;
    }

    public function mount(): void
    {
        $account = $this->managedAccount();
        $settings = $account->aiAccountSetting;

        if ($settings instanceof AiAccountSetting) {
            $this->paidAiEnabled = $settings->paid_ai_enabled;
            $this->hostedAiEnabled = $settings->hosted_ai_enabled;
            $this->byokEnabled = $settings->byok_enabled;
            $this->monthlyUsdLimit = $settings->monthly_usd_limit;
            $this->perOperationUsdLimit = $settings->per_operation_usd_limit;
            $this->maxConcurrency = $settings->max_concurrency;
        }

        foreach (AiModelPurpose::cases() as $purpose) {
            $preference = $account->aiModelPreferences()->where('purpose', $purpose->value)->first();
            $this->modelModes[$purpose->value] = $preference instanceof AiModelPreference
                ? $preference->mode->value
                : AiModelMode::Auto->value;
            $this->models[$purpose->value] = $preference instanceof AiModelPreference
                ? ($preference->model_ids ?? [])
                : [];
            $this->newModels[$purpose->value] = '';
        }

        $this->credentialLastFour = $account->aiProviderCredentials()
            ->where('provider', 'openrouter')->whereNull('revoked_at')->value('last_four');
    }

    public function saveSettings(AccountMutationGate $accountMutationGate, AiAuditLedger $auditLedger): void
    {
        $account = $this->managedAccount();
        $validated = $this->validate([
            'paidAiEnabled' => ['boolean'],
            'hostedAiEnabled' => ['boolean'],
            'byokEnabled' => ['boolean'],
            'monthlyUsdLimit' => ['nullable', 'numeric', 'min:0.01', 'max:100000'],
            'perOperationUsdLimit' => ['nullable', 'numeric', 'min:0.0001', 'max:10000'],
            'maxConcurrency' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        if ($validated['byokEnabled'] && $this->credentialLastFour === null) {
            $this->addError('byokEnabled', 'Save an OpenRouter API key before enabling BYOK.');

            return;
        }

        DB::transaction(function () use ($account, $validated, $accountMutationGate, $auditLedger): void {
            $lockedAccount = $accountMutationGate->lockManagerOrFail($account->id, $this->user()->id);

            if ($validated['byokEnabled']) {
                $activeCredential = AiProviderCredential::query()
                    ->whereBelongsTo($lockedAccount)
                    ->where('provider', 'openrouter')
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();

                if (! $activeCredential instanceof AiProviderCredential) {
                    throw ValidationException::withMessages([
                        'byokEnabled' => 'Save an active OpenRouter API key before enabling BYOK.',
                    ]);
                }
            }

            $settings = AiAccountSetting::query()
                ->whereBelongsTo($lockedAccount)
                ->lockForUpdate()
                ->first();
            $before = $this->settingsAuditState($settings);
            $after = [
                'paid_ai_enabled' => $validated['paidAiEnabled'],
                'hosted_ai_enabled' => $validated['hostedAiEnabled'],
                'byok_enabled' => $validated['byokEnabled'],
                'monthly_usd_limit' => $validated['monthlyUsdLimit'],
                'per_operation_usd_limit' => $validated['perOperationUsdLimit'],
                'max_concurrency' => $validated['maxConcurrency'],
            ];

            $lockedAccount->aiAccountSetting()->updateOrCreate([], [
                'user_id' => $this->user()->id,
                ...$after,
            ]);

            $auditLedger->appendControlChange(
                $lockedAccount,
                $this->user(),
                AiAuditEvent::ControlsChanged,
                $this->changedFields($before, $after),
                $before,
                $after,
            );
        }, attempts: 3);

        $this->dispatch('ai-settings-saved');
    }

    public function addModel(string $purpose): void
    {
        $this->managedAccount();
        $purposeEnum = AiModelPurpose::from($purpose);
        $model = trim($this->newModels[$purpose] ?? '');
        $candidate = [...($this->models[$purpose] ?? []), $model];
        $this->models[$purpose] = array_values(array_unique($candidate));
        $this->newModels[$purpose] = '';
        $this->validateModels($purposeEnum);
    }

    public function removeModel(string $purpose, int $index): void
    {
        $this->managedAccount();
        AiModelPurpose::from($purpose);
        unset($this->models[$purpose][$index]);
        $this->models[$purpose] = array_values($this->models[$purpose]);
    }

    public function saveModels(AccountMutationGate $accountMutationGate, AiAuditLedger $auditLedger): void
    {
        $account = $this->managedAccount();
        $user = $this->user();
        $rules = [];

        foreach (AiModelPurpose::cases() as $purpose) {
            if ($purpose === AiModelPurpose::Auto) {
                continue;
            }

            $key = $purpose->value;
            $rules["modelModes.{$key}"] = ['required', Rule::enum(AiModelMode::class)];
            $rules["models.{$key}"] = ['array', 'max:'.$this->maximumModelsFor($purpose)];
            $rules["models.{$key}.*"] = ['string', 'max:191', 'regex:'.config('account-ai.model_id_pattern')];
        }

        $this->validate($rules);

        foreach ([AiModelPurpose::ShortText, AiModelPurpose::LongText, AiModelPurpose::ImagePrompt, AiModelPurpose::Image] as $purpose) {
            $key = $purpose->value;

            if (($this->modelModes[$key] ?? null) === AiModelMode::Custom->value
                && ($this->models[$key] ?? []) === []) {
                throw ValidationException::withMessages([
                    "models.{$key}" => 'Add at least one model when custom mode is selected.',
                ]);
            }
        }

        DB::transaction(function () use ($account, $user, $accountMutationGate, $auditLedger): void {
            $lockedAccount = $accountMutationGate->lockManagerOrFail($account->id, $user->id);
            $existing = AiModelPreference::query()
                ->whereBelongsTo($lockedAccount)
                ->orderBy('purpose')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (AiModelPreference $preference): string => $preference->purpose->value);
            $before = [];
            $after = [];

            foreach (AiModelPurpose::cases() as $purpose) {
                $key = $purpose->value;
                $mode = $purpose === AiModelPurpose::Auto
                    ? AiModelMode::Auto->value
                    : $this->modelModes[$key];
                $models = $purpose === AiModelPurpose::Auto ? [] : $this->models[$key];
                $preference = $existing->get($key);
                $before[$key] = [
                    'mode' => $preference instanceof AiModelPreference ? $preference->mode->value : AiModelMode::Auto->value,
                    'models' => $preference instanceof AiModelPreference ? ($preference->model_ids ?? []) : [],
                ];
                $after[$key] = ['mode' => $mode, 'models' => $models];

                AiModelPreference::query()->updateOrCreate(
                    ['account_id' => $account->id, 'purpose' => $key],
                    ['user_id' => $user->id, 'mode' => $mode, 'model_ids' => $models],
                );
            }

            $changedPurposes = array_values(array_filter(
                array_keys($after),
                fn (string $purpose): bool => $before[$purpose] !== $after[$purpose],
            ));
            $auditLedger->appendControlChange(
                $lockedAccount,
                $user,
                AiAuditEvent::RoutingChanged,
                array_map(fn (string $purpose): string => 'routing.'.$purpose, $changedPurposes),
                $before,
                $after,
            );
        }, attempts: 3);

        $this->dispatch('ai-models-saved');
    }

    public function render(): View
    {
        return view('livewire.settings.ai');
    }

    private function validateModels(AiModelPurpose $purpose): void
    {
        if ($purpose === AiModelPurpose::Auto) {
            $this->modelModes[$purpose->value] = AiModelMode::Auto->value;
            $this->models[$purpose->value] = [];

            return;
        }

        $key = $purpose->value;
        $this->validate([
            "modelModes.{$key}" => ['required', Rule::enum(AiModelMode::class)],
            "models.{$key}" => ['array', 'max:'.$this->maximumModelsFor($purpose)],
            "models.{$key}.*" => ['required_if:modelModes.'.$key.',custom', 'string', 'max:191', 'regex:'.config('account-ai.model_id_pattern')],
        ]);

        if ($this->modelModes[$key] === AiModelMode::Custom->value && $this->models[$key] === []) {
            throw ValidationException::withMessages([
                "models.{$key}" => 'Add at least one model when custom mode is selected.',
            ]);
        }
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function managedAccount(): Account
    {
        $user = $this->user();
        $account = $this->currentAccount->resolve($user);
        Gate::authorize('manageAi', $account);

        return $account;
    }

    private function maximumModelsFor(AiModelPurpose $purpose): int
    {
        $maximum = config("account-ai.max_custom_models_by_purpose.{$purpose->value}");

        return is_numeric($maximum)
            ? (int) $maximum
            : (int) config('account-ai.max_custom_models', 12);
    }

    /** @return array<string, bool|float|int|null> */
    private function settingsAuditState(?AiAccountSetting $settings): array
    {
        if (! $settings instanceof AiAccountSetting) {
            return [
                'paid_ai_enabled' => true,
                'hosted_ai_enabled' => true,
                'byok_enabled' => false,
                'monthly_usd_limit' => null,
                'per_operation_usd_limit' => null,
                'max_concurrency' => 1,
            ];
        }

        return [
            'paid_ai_enabled' => $settings->paid_ai_enabled,
            'hosted_ai_enabled' => $settings->hosted_ai_enabled,
            'byok_enabled' => $settings->byok_enabled,
            'monthly_usd_limit' => $settings->monthly_usd_limit !== null ? (float) $settings->monthly_usd_limit : null,
            'per_operation_usd_limit' => $settings->per_operation_usd_limit !== null ? (float) $settings->per_operation_usd_limit : null,
            'max_concurrency' => $settings->max_concurrency,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, string>
     */
    private function changedFields(array $before, array $after): array
    {
        return array_values(array_filter(
            array_keys($after),
            fn (string $field): bool => $before[$field] !== $after[$field],
        ));
    }
}
