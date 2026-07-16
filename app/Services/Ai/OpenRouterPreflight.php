<?php

namespace App\Services\Ai;

use App\Contracts\OpenRouterPreflightClient;
use App\Enums\AccountCapability;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Exceptions\OpenRouterPreflightFailed;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\AiAccountSetting;
use App\Models\AiModelPreference;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Accounts\AccountEntitlementGate;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Throwable;

final class OpenRouterPreflight
{
    public function __construct(
        private AccountMutationGate $accountMutationGate,
        private AccountEntitlementGate $entitlements,
        private OpenRouterPreflightClient $client,
        private AiAuditLedger $auditLedger,
    ) {}

    public function run(User $actor, Account $account): OpenRouterPreflightResult
    {
        $operationId = (string) Str::uuid7();
        $prepared = DB::transaction(function () use ($actor, $account, $operationId): array|OpenRouterPreflightResult {
            $lockedAccount = $this->accountMutationGate->lockManagerOrFail($account->id, $actor->id);
            $entitlement = AccountEntitlement::query()
                ->whereBelongsTo($lockedAccount)
                ->lockForUpdate()
                ->first();
            $lockedAccount->setRelation('entitlement', $entitlement);

            if (! $this->entitlements->allows($lockedAccount, AccountCapability::HostedAi)) {
                return $this->prevented($lockedAccount, $actor, $operationId, null, 'capability_unavailable');
            }

            $credential = AiProviderCredential::query()
                ->whereBelongsTo($lockedAccount)
                ->where('provider', 'openrouter')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            $configuration = $this->configurationSnapshot($lockedAccount);

            $this->auditLedger->append([
                'operation_id' => $operationId,
                'account_id' => $lockedAccount->id,
                'actor_user_id' => $actor->id,
                'event' => AiAuditEvent::PreflightStarted,
                'provider' => 'openrouter',
                'credential_fingerprint' => $credential?->fingerprint,
                'request_hash' => $configuration['fingerprint'],
                'request_bytes' => $this->configuredModelCount($configuration['models']),
            ]);

            if (! $credential instanceof AiProviderCredential) {
                return $this->prevented(
                    $lockedAccount,
                    $actor,
                    $operationId,
                    null,
                    'credential_unavailable',
                    started: true,
                    requestHash: $configuration['fingerprint'],
                );
            }

            $rateKey = 'openrouter-preflight|'.$lockedAccount->id.'|'.$actor->id;

            if (RateLimiter::tooManyAttempts($rateKey, 5)) {
                return $this->prevented(
                    $lockedAccount,
                    $actor,
                    $operationId,
                    $credential,
                    'rate_limited',
                    started: true,
                    requestHash: $configuration['fingerprint'],
                );
            }

            RateLimiter::hit($rateKey, 3600);

            return [
                'account_id' => $lockedAccount->id,
                'actor_user_id' => $actor->id,
                'credential_id' => $credential->id,
                'credential_fingerprint' => $credential->fingerprint,
                'credential' => $credential,
                'configured' => $configuration['models'],
                'configuration_fingerprint' => $configuration['fingerprint'],
            ];
        }, attempts: 3);

        if ($prepared instanceof OpenRouterPreflightResult) {
            return $prepared;
        }

        $provider = null;
        $providerFailure = null;

        try {
            $this->client->assertConfiguredEndpointSafe();
            $credential = $prepared['credential'];
            $provider = $this->client->inspect($credential->secret);
        } catch (OpenRouterPreflightFailed $exception) {
            $providerFailure = $exception->reason;
        } catch (Throwable) {
            $providerFailure = 'provider_unavailable';
        }

        if ($providerFailure !== null) {
            return $this->failed($prepared, $operationId, $providerFailure, null, null, []);
        }

        if (! is_array($provider)) {
            return $this->failed($prepared, $operationId, 'provider_unavailable', null, null, []);
        }

        if (! $provider['key_valid']) {
            return $this->failed($prepared, $operationId, 'invalid_key', false, null, []);
        }

        $models = $this->validateModels($prepared['configured'], $provider['models']);

        if (collect($models)->contains(fn (array $model): bool => ! $model['exists'] || ! $model['compatible'])) {
            return $this->failed(
                $prepared,
                $operationId,
                'configuration_invalid',
                true,
                $provider['label'],
                $models,
            );
        }

        if (! $this->appendTerminalIfCurrent(
            $prepared,
            $operationId,
            AiAuditEvent::PreflightSucceeded,
            null,
            $models,
        )) {
            return $this->stateChanged($prepared, $operationId);
        }

        return new OpenRouterPreflightResult(
            $operationId,
            'succeeded',
            true,
            $provider['label'],
            $models,
            __('The key is valid and every configured model supports its required output.'),
        );
    }

    /**
     * @return array{models: array<string, array{mode: string, models: array<int, string>}>, fingerprint: string}
     */
    private function configurationSnapshot(Account $account): array
    {
        $settings = AiAccountSetting::query()
            ->whereBelongsTo($account)
            ->lockForUpdate()
            ->first();
        $models = $this->configuredModels($account);
        $controls = $settings instanceof AiAccountSetting
            ? [
                'paid_ai_enabled' => $settings->paid_ai_enabled,
                'hosted_ai_enabled' => $settings->hosted_ai_enabled,
                'byok_enabled' => $settings->byok_enabled,
                'monthly_usd_limit' => $settings->monthly_usd_limit,
                'per_operation_usd_limit' => $settings->per_operation_usd_limit,
                'max_concurrency' => $settings->max_concurrency,
            ]
            : [
                'paid_ai_enabled' => true,
                'hosted_ai_enabled' => true,
                'byok_enabled' => false,
                'monthly_usd_limit' => null,
                'per_operation_usd_limit' => null,
                'max_concurrency' => 1,
            ];

        return [
            'models' => $models,
            'fingerprint' => $this->auditLedger->fingerprint([
                'controls' => $controls,
                'models' => $models,
            ]),
        ];
    }

    /** @param array<string, mixed> $prepared
     * @param  array<int, array{purpose: string, model: string, exists: bool, compatible: bool, required_modality: string}>  $models
     */
    private function appendTerminalIfCurrent(
        array $prepared,
        string $operationId,
        AiAuditEvent $event,
        ?string $reason,
        array $models,
    ): bool {
        try {
            return DB::transaction(function () use ($prepared, $operationId, $event, $reason, $models): bool {
                $lockedAccount = $this->accountMutationGate->lockManagerOrFail(
                    (int) $prepared['account_id'],
                    (int) $prepared['actor_user_id'],
                );
                $entitlement = AccountEntitlement::query()
                    ->whereBelongsTo($lockedAccount)
                    ->lockForUpdate()
                    ->first();
                $lockedAccount->setRelation('entitlement', $entitlement);

                if (! $this->entitlements->allows($lockedAccount, AccountCapability::HostedAi)) {
                    return false;
                }

                $credential = AiProviderCredential::query()
                    ->whereBelongsTo($lockedAccount)
                    ->where('provider', 'openrouter')
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();

                if (! $credential instanceof AiProviderCredential
                    || $credential->id !== (int) $prepared['credential_id']
                    || ! hash_equals((string) $prepared['credential_fingerprint'], $credential->fingerprint)) {
                    return false;
                }

                $configuration = $this->configurationSnapshot($lockedAccount);

                $matchesConfiguration = hash_equals(
                    (string) $prepared['configuration_fingerprint'],
                    $configuration['fingerprint'],
                );

                if (! $matchesConfiguration) {
                    return false;
                }

                $this->appendOutcomeRecord($prepared, $operationId, $event, $reason, $models);

                return true;
            }, attempts: 3);
        } catch (AuthorizationException|GoneHttpException) {
            return false;
        }
    }

    /**
     * @return array<string, array{mode: string, models: array<int, string>}>
     */
    private function configuredModels(Account $account): array
    {
        $preferences = AiModelPreference::query()
            ->whereBelongsTo($account)
            ->orderBy('purpose')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (AiModelPreference $preference): string => $preference->purpose->value);
        $configured = [];

        foreach (AiModelPurpose::cases() as $purpose) {
            $preference = $preferences->get($purpose->value);
            $isCustom = $purpose !== AiModelPurpose::Auto
                && $preference?->mode === AiModelMode::Custom
                && ($preference->model_ids ?? []) !== [];
            $configured[$purpose->value] = [
                'mode' => $isCustom ? AiModelMode::Custom->value : AiModelMode::Auto->value,
                'models' => $isCustom
                    ? array_values($preference->model_ids)
                    : array_values(config('account-ai.auto_models.'.$purpose->value, [])),
            ];
        }

        return $configured;
    }

    /**
     * @param  array<string, array{mode: string, models: array<int, string>}>  $configured
     * @param  array<string, array<int, string>>  $availableModels
     * @return array<int, array{purpose: string, model: string, exists: bool, compatible: bool, required_modality: string}>
     */
    private function validateModels(array $configured, array $availableModels): array
    {
        $results = [];

        foreach ($configured as $purpose => $configuration) {
            $purposeEnum = AiModelPurpose::from($purpose);
            $requiredModality = $purposeEnum === AiModelPurpose::Image ? 'image' : 'text';

            foreach ($configuration['models'] as $model) {
                $modalities = $availableModels[$model] ?? null;
                $results[] = [
                    'purpose' => $purpose,
                    'model' => $model,
                    'exists' => is_array($modalities),
                    'compatible' => is_array($modalities) && in_array($requiredModality, $modalities, true),
                    'required_modality' => $requiredModality,
                ];
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $prepared
     * @param  array<int, array{purpose: string, model: string, exists: bool, compatible: bool, required_modality: string}>  $models
     */
    private function failed(
        array $prepared,
        string $operationId,
        string $reason,
        ?bool $keyValid,
        ?string $label,
        array $models,
    ): OpenRouterPreflightResult {
        if (! $this->appendTerminalIfCurrent(
            $prepared,
            $operationId,
            AiAuditEvent::PreflightFailed,
            $reason,
            $models,
        )) {
            return $this->stateChanged($prepared, $operationId);
        }

        return new OpenRouterPreflightResult(
            $operationId,
            'failed',
            $keyValid,
            $label,
            $models,
            $this->messageFor($reason),
        );
    }

    /** @param array<string, mixed> $prepared */
    private function stateChanged(array $prepared, string $operationId): OpenRouterPreflightResult
    {
        DB::transaction(function () use ($prepared, $operationId): void {
            $this->appendOutcomeRecord(
                $prepared,
                $operationId,
                AiAuditEvent::PreflightPrevented,
                'state_changed',
                [],
            );
        }, attempts: 3);

        return new OpenRouterPreflightResult(
            $operationId,
            'prevented',
            null,
            null,
            [],
            $this->messageFor('state_changed'),
        );
    }

    private function prevented(
        Account $account,
        User $actor,
        string $operationId,
        ?AiProviderCredential $credential,
        string $reason,
        bool $started = false,
        ?string $requestHash = null,
    ): OpenRouterPreflightResult {
        if (! $started) {
            $this->auditLedger->append([
                'operation_id' => $operationId,
                'account_id' => $account->id,
                'actor_user_id' => $actor->id,
                'event' => AiAuditEvent::PreflightStarted,
                'provider' => 'openrouter',
            ]);
        }

        $this->auditLedger->append([
            'operation_id' => $operationId,
            'account_id' => $account->id,
            'actor_user_id' => $actor->id,
            'event' => AiAuditEvent::PreflightPrevented,
            'provider' => 'openrouter',
            'credential_fingerprint' => $credential?->fingerprint,
            'request_hash' => $requestHash,
            'error_code' => $reason,
        ]);

        return new OpenRouterPreflightResult(
            $operationId,
            'prevented',
            null,
            null,
            [],
            $this->messageFor($reason),
        );
    }

    /** @param array<string, mixed> $prepared
     * @param  array<int, array{purpose: string, model: string, exists: bool, compatible: bool, required_modality: string}>  $models
     */
    private function appendOutcomeRecord(
        array $prepared,
        string $operationId,
        AiAuditEvent $event,
        ?string $reason,
        array $models,
    ): void {
        $this->auditLedger->append([
            'operation_id' => $operationId,
            'account_id' => $prepared['account_id'],
            'actor_user_id' => $prepared['actor_user_id'],
            'event' => $event,
            'provider' => 'openrouter',
            'credential_fingerprint' => $prepared['credential_fingerprint'],
            'request_hash' => $prepared['configuration_fingerprint'],
            'output_hash' => $models !== [] ? $this->auditLedger->fingerprint(['models' => $models]) : null,
            'output_bytes' => count($models),
            'error_code' => $reason,
        ]);
    }

    /** @param array<string, array{mode: string, models: array<int, string>}> $configured */
    private function configuredModelCount(array $configured): int
    {
        return array_sum(array_map(fn (array $configuration): int => count($configuration['models']), $configured));
    }

    private function messageFor(string $reason): string
    {
        return match ($reason) {
            'capability_unavailable' => __('This workspace is not entitled to AI operations.'),
            'credential_unavailable' => __('Save an active OpenRouter key before running preflight.'),
            'rate_limited' => __('Preflight is limited to five attempts per hour for this manager and workspace.'),
            'invalid_key' => __('OpenRouter rejected the configured key.'),
            'configuration_invalid' => __('The key is valid, but one or more configured models are missing or incompatible.'),
            'state_changed' => __('Account AI state changed during preflight. No provider result was accepted.'),
            default => __('OpenRouter preflight is temporarily unavailable. Try again later.'),
        };
    }
}
