<?php

namespace App\Livewire\Settings;

use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\Ai\AiTrustCenterReadModel;
use App\Services\Ai\OpenRouterPreflight;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class AiTrust extends Component
{
    use WithPagination;

    public string $event = '';

    public string $outcome = '';

    public string $actor = '';

    public string $purpose = '';

    public string $provider = '';

    public string $model = '';

    public string $operationId = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array{operation_id: string, status: string, key_valid: bool|null, label: string|null, models: array<int, array{purpose: string, model: string, exists: bool, compatible: bool, required_modality: string}>, message: string}|array{} */
    public array $preflightResult = [];

    public function mount(CurrentAccount $currentAccount): void
    {
        $this->managedAccount($currentAccount);
    }

    public function updated(string $property): void
    {
        if (array_key_exists($property, $this->rules())) {
            $this->validateOnly($property);
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['event', 'outcome', 'actor', 'purpose', 'provider', 'model', 'operationId', 'dateFrom', 'dateTo']);
        $this->resetValidation();
        $this->resetPage();
    }

    public function runPreflight(CurrentAccount $currentAccount, OpenRouterPreflight $preflight): void
    {
        $account = $this->managedAccount($currentAccount);
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 10_800);

        if ($confirmedAt < now()->subSeconds($timeout)->getTimestamp()) {
            $this->addError('preflight', __('Confirm your password before contacting OpenRouter.'));

            return;
        }

        $this->resetErrorBag('preflight');
        $result = $preflight->run($this->user(), $account);
        $this->preflightResult = [
            'operation_id' => $result->operationId,
            'status' => $result->status,
            'key_valid' => $result->keyValid,
            'label' => $result->label,
            'models' => $result->models,
            'message' => $result->message,
        ];

        $this->dispatch('openrouter-preflight-finished');
    }

    public function render(CurrentAccount $currentAccount, AiTrustCenterReadModel $readModel): View
    {
        $account = $this->managedAccount($currentAccount);
        $filters = $this->filters();

        return view('livewire.settings.ai-trust', [
            'audits' => $readModel->timeline($account, $filters),
            'actors' => $readModel->actors($account),
            'usage' => $readModel->usage($account),
            'routing' => $readModel->routing($account),
            'eventOptions' => AiAuditEvent::cases(),
            'purposeOptions' => AiModelPurpose::cases(),
            'exportUrl' => route('ai.trust.export', array_filter($filters, fn (mixed $value): bool => filled($value))),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    protected function rules(): array
    {
        return [
            'event' => ['nullable', Rule::enum(AiAuditEvent::class)],
            'outcome' => ['nullable', Rule::in(['started', 'succeeded', 'failed', 'prevented', 'recorded'])],
            'actor' => ['nullable', 'integer', 'min:1'],
            'purpose' => ['nullable', Rule::enum(AiModelPurpose::class)],
            'provider' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'model' => ['nullable', 'string', 'max:80'],
            'operationId' => ['nullable', 'uuid'],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
        ];
    }

    /** @return array<string, int|string|null> */
    private function filters(): array
    {
        return [
            'event' => $this->event,
            'outcome' => $this->outcome,
            'actor' => $this->actor,
            'purpose' => $this->purpose,
            'provider' => $this->provider,
            'model' => $this->model,
            'operation_id' => $this->operationId,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
        ];
    }

    private function managedAccount(CurrentAccount $currentAccount): Account
    {
        $user = $this->user();
        $account = $currentAccount->resolve($user);
        Gate::authorize('manageAi', $account);

        return $account;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
