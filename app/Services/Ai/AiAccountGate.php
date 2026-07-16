<?php

namespace App\Services\Ai;

use App\Enums\AccountCapability;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Exceptions\PaidAiUnavailable;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiAccountSetting;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Accounts\AccountEntitlementGate;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class AiAccountGate
{
    public function __construct(
        private CurrentAccount $currentAccount,
        private AccountEntitlementGate $entitlements,
        private AccountMutationGate $accountMutationGate,
    ) {}

    public function authorize(
        User $user,
        AiModelPurpose $purpose,
        string $hostedProvider,
        string $hostedModel,
        ?float $hostedEstimatedCostUsd = null,
        ?string $requestedByokModel = null,
        ?bool $expectByok = null,
        Account|int|null $account = null,
    ): AiExecutionContext {
        return DB::transaction(function () use (
            $user,
            $purpose,
            $hostedProvider,
            $hostedModel,
            $hostedEstimatedCostUsd,
            $requestedByokModel,
            $expectByok,
            $account,
        ): AiExecutionContext {
            $account = $this->accountFor($user, $account);
            try {
                $account = $this->accountMutationGate->lockActiveMemberOrFail(
                    $account->id,
                    $user->id,
                );
            } catch (AuthorizationException|GoneHttpException) {
                throw PaidAiUnavailable::routeUnavailable();
            }

            $entitlement = AccountEntitlement::query()
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();
            $account->setRelation('entitlement', $entitlement);

            // Hosted and BYOK execution are one paid-AI capability. Provider
            // routing and credentials are never resolved without it.
            if (! $this->entitlements->allows($account, AccountCapability::HostedAi)) {
                throw PaidAiUnavailable::entitlementRequired();
            }

            $settings = AiAccountSetting::query()->firstOrCreate(
                ['account_id' => $account->id],
                [
                    'user_id' => $user->id,
                    'paid_ai_enabled' => true,
                    'hosted_ai_enabled' => true,
                    'byok_enabled' => false,
                    'max_concurrency' => 1,
                ],
            );
            $settings = AiAccountSetting::query()->whereKey($settings)->lockForUpdate()->firstOrFail();

            if (! $settings->paid_ai_enabled) {
                throw PaidAiUnavailable::disabled();
            }

            $credential = $settings->byok_enabled
                ? AiProviderCredential::query()
                    ->whereBelongsTo($account)
                    ->where('provider', 'openrouter')
                    ->whereNull('revoked_at')
                    ->first()
                : null;
            $usesByok = $credential instanceof AiProviderCredential;

            if (($expectByok === true && ! $usesByok) || ($expectByok === false && $usesByok)) {
                throw PaidAiUnavailable::routeUnavailable();
            }

            if ($usesByok) {
                $models = $this->modelsFor($account, $purpose, $hostedModel);

                if ($requestedByokModel !== null && ! in_array($requestedByokModel, $models, true)) {
                    throw PaidAiUnavailable::routeUnavailable();
                }

                $provider = 'openrouter';
                $model = $requestedByokModel ?? $models[0];
                $estimatedCostUsd = $hostedProvider === 'openrouter' && $hostedModel === $model
                    ? ($hostedEstimatedCostUsd ?? $this->modelEstimate($purpose, $model))
                    : $this->modelEstimate($purpose, $model);
            } else {
                if (! $settings->hosted_ai_enabled || $requestedByokModel !== null) {
                    throw PaidAiUnavailable::routeUnavailable();
                }

                $provider = $hostedProvider;
                $model = $hostedModel;
                $estimatedCostUsd = $hostedEstimatedCostUsd
                    ?? $this->defaultEstimate($purpose);
            }

            $hasBudgetLimit = $settings->per_operation_usd_limit !== null
                || $settings->monthly_usd_limit !== null;

            if ($hasBudgetLimit && $estimatedCostUsd === null) {
                throw PaidAiUnavailable::budgetEstimateUnavailable();
            }

            if ($settings->per_operation_usd_limit !== null
                && $estimatedCostUsd !== null
                && $estimatedCostUsd > (float) $settings->per_operation_usd_limit) {
                throw PaidAiUnavailable::budgetExceeded();
            }

            $activeQuery = AiOperationAudit::query()
                ->where('account_id', $account->id)
                ->where('event', AiAuditEvent::Started->value)
                ->where('occurred_at', '>=', now()->subMinutes(15))
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('ai_operation_audits as completion')
                        ->whereColumn('completion.operation_id', 'ai_operation_audits.operation_id')
                        ->whereColumn('completion.account_id', 'ai_operation_audits.account_id')
                        ->whereIn('completion.event', [AiAuditEvent::Succeeded->value, AiAuditEvent::Failed->value]);
                });

            if ($settings->monthly_usd_limit !== null) {
                $spent = (float) AiOperationAudit::query()
                    ->where('account_id', $account->id)
                    ->where('event', AiAuditEvent::Succeeded->value)
                    ->where('occurred_at', '>=', now()->startOfMonth())
                    ->sum('cost_usd');
                $reserved = (float) (clone $activeQuery)
                    ->where('occurred_at', '>=', now()->startOfMonth())
                    ->sum('cost_usd');

                if ($spent + $reserved + ($estimatedCostUsd ?? 0.0) > (float) $settings->monthly_usd_limit) {
                    throw PaidAiUnavailable::budgetExceeded();
                }
            }

            $activeOperations = (clone $activeQuery)->count();

            if ($activeOperations >= $settings->max_concurrency) {
                throw PaidAiUnavailable::concurrencyExceeded();
            }

            return new AiExecutionContext($account->id, $provider, $model, $credential, $estimatedCostUsd);
        }, attempts: 3);
    }

    /** @return array<int, string>|null */
    public function activeByokModels(User $user, AiModelPurpose $purpose, Account|int|null $account = null): ?array
    {
        try {
            $account = $this->accountFor($user, $account);
        } catch (PaidAiUnavailable) {
            return null;
        }

        if (! $this->entitlements->allows($account, AccountCapability::HostedAi)) {
            return null;
        }

        $settings = $account->aiAccountSetting;

        if (! $settings instanceof AiAccountSetting || ! $settings->paid_ai_enabled || ! $settings->byok_enabled) {
            return null;
        }

        $hasCredential = $account->aiProviderCredentials()
            ->where('provider', 'openrouter')
            ->whereNull('revoked_at')
            ->exists();

        return $hasCredential ? $this->modelsFor($account, $purpose, 'openrouter/auto') : null;
    }

    /** @return array<int, string> */
    public function modelsFor(Account $account, AiModelPurpose $purpose, string $fallback): array
    {
        $preference = $account->aiModelPreferences()->where('purpose', $purpose->value)->first();

        if ($preference?->mode === AiModelMode::Custom && is_array($preference->model_ids) && $preference->model_ids !== []) {
            return array_values(array_unique(array_map('strval', $preference->model_ids)));
        }

        $models = config("account-ai.auto_models.{$purpose->value}", []);

        return is_array($models) && $models !== []
            ? array_values(array_unique(array_map('strval', $models)))
            : [$fallback];
    }

    public function accountFor(User $user, Account|int|null $account = null): Account
    {
        if ($account === null) {
            try {
                return $this->currentAccount->resolve($user);
            } catch (AuthorizationException) {
                throw PaidAiUnavailable::routeUnavailable();
            }
        }

        $accountId = $account instanceof Account ? $account->getKey() : $account;
        $resolvedAccount = Account::query()
            ->whereKey($accountId)
            ->whereNull('erasure_started_at')
            ->whereHas('members', fn ($query) => $query->whereKey($user->id))
            ->first();

        return $resolvedAccount
            ?? throw PaidAiUnavailable::routeUnavailable();
    }

    private function defaultEstimate(AiModelPurpose $purpose): ?float
    {
        $estimate = config("account-ai.default_estimated_cost_usd.{$purpose->value}");

        return is_numeric($estimate) ? (float) $estimate : null;
    }

    private function modelEstimate(AiModelPurpose $purpose, string $model): ?float
    {
        if ($purpose === AiModelPurpose::Image) {
            $profile = config('photostudio.models.openrouter', [])[$model] ?? null;

            return is_array($profile) && isset($profile['usd_per_image'])
                ? (float) $profile['usd_per_image']
                : null;
        }

        $estimate = config('account-ai.model_estimated_cost_usd', [])[$model] ?? null;

        return is_numeric($estimate) ? (float) $estimate : null;
    }
}
