<?php

namespace App\Services\Ai;

use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use App\Exceptions\PaidAiUnavailable;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AuditedAiExecutor
{
    public function __construct(private AiAccountGate $gate) {}

    /**
     * @template TResult
     *
     * @param  Closure(AiExecutionContext): TResult  $operation
     * @param  (Closure(TResult): string)|null  $outputText
     * @param  (Closure(TResult): AiOperationResultMetadata)|null  $resultMetadata
     * @return TResult
     */
    public function execute(
        User $user,
        AiModelPurpose $purpose,
        string $provider,
        string $model,
        string $requestPayload,
        Closure $operation,
        ?Closure $outputText = null,
        ?float $hostedEstimatedCostUsd = null,
        ?Closure $resultMetadata = null,
        ?string $requestedByokModel = null,
        ?bool $expectByok = null,
        Account|int|null $account = null,
    ): mixed {
        $operationId = (string) Str::uuid7();

        try {
            $context = DB::transaction(function () use (
                $operationId,
                $user,
                $purpose,
                $provider,
                $model,
                $hostedEstimatedCostUsd,
                $requestPayload,
                $requestedByokModel,
                $expectByok,
                $account,
            ): AiExecutionContext {
                $context = $this->gate->authorize(
                    $user,
                    $purpose,
                    $provider,
                    $model,
                    $hostedEstimatedCostUsd,
                    $requestedByokModel,
                    $expectByok,
                    $account,
                );
                $this->append(
                    $operationId,
                    $user,
                    AiAuditEvent::Started,
                    $purpose,
                    $context,
                    $requestPayload,
                    costUsd: $context->estimatedCostUsd,
                );

                return $context;
            }, attempts: 3);
        } catch (Throwable $exception) {
            if (! $exception instanceof PaidAiUnavailable || $exception->getMessage() !== PaidAiUnavailable::auditUnavailable()->getMessage()) {
                $this->appendPrevented($operationId, $user, $purpose, $provider, $model, $requestPayload, $exception, $account);
            }

            throw $exception;
        }

        try {
            $result = $operation($context);
        } catch (Throwable $exception) {
            try {
                $this->append($operationId, $user, AiAuditEvent::Failed, $purpose, $context, $requestPayload, null, $exception);
            } catch (Throwable) {
                throw PaidAiUnavailable::auditUnavailable();
            }

            throw $exception;
        }

        try {
            $output = $outputText instanceof Closure ? $outputText($result) : null;
            $metadata = $resultMetadata instanceof Closure ? $resultMetadata($result) : null;
        } catch (Throwable $exception) {
            try {
                $this->append($operationId, $user, AiAuditEvent::Failed, $purpose, $context, $requestPayload, null, $exception);
            } catch (Throwable) {
                throw PaidAiUnavailable::auditUnavailable();
            }

            throw PaidAiUnavailable::auditUnavailable();
        }

        $actualCostUsd = $metadata === null
            ? $context->estimatedCostUsd
            : ($metadata->costUsd ?? $context->estimatedCostUsd);

        try {
            $this->append(
                $operationId,
                $user,
                AiAuditEvent::Succeeded,
                $purpose,
                $context,
                $requestPayload,
                $output,
                metadata: $metadata,
                costUsd: $actualCostUsd,
            );
        } catch (Throwable) {
            throw PaidAiUnavailable::auditUnavailable();
        }

        return $result;
    }

    public function recordPreProviderDenial(
        User $user,
        AiModelPurpose $purpose,
        string $provider,
        string $model,
        string $requestPayload,
        Account|int|null $account = null,
    ): void {
        $this->appendPrevented(
            (string) Str::uuid7(),
            $user,
            $purpose,
            $provider,
            $model,
            $requestPayload,
            PaidAiUnavailable::routeUnavailable(),
            $account,
        );
    }

    private function append(
        string $operationId,
        User $user,
        AiAuditEvent $event,
        AiModelPurpose $purpose,
        AiExecutionContext $context,
        string $requestPayload,
        ?string $output = null,
        ?Throwable $exception = null,
        ?AiOperationResultMetadata $metadata = null,
        ?float $costUsd = null,
    ): void {
        AiOperationAudit::query()->create([
            'operation_id' => $operationId,
            'account_id' => $context->accountId,
            'actor_user_id' => $user->id,
            'event' => $event,
            'purpose' => $purpose,
            'provider' => $metadata === null ? $context->provider : $metadata->provider,
            'model' => $metadata === null ? $context->model : $metadata->model,
            'credential_fingerprint' => $context->credential?->fingerprint,
            'request_hash' => $this->contentHash($requestPayload),
            'request_bytes' => strlen($requestPayload),
            'output_hash' => $output !== null ? $this->contentHash($output) : null,
            'output_bytes' => $output !== null ? strlen($output) : null,
            'input_tokens' => $metadata?->inputTokens,
            'output_tokens' => $metadata?->outputTokens,
            'cost_usd' => $costUsd,
            'error_code' => $exception !== null ? 'provider_failure' : null,
            'exception_class' => $exception !== null ? $exception::class : null,
            'ip_hash' => $this->requestHash(request()->ip()),
            'user_agent_hash' => $this->requestHash(request()->userAgent()),
            'occurred_at' => now(),
        ]);
    }

    private function appendPrevented(
        string $operationId,
        User $user,
        AiModelPurpose $purpose,
        string $provider,
        string $model,
        string $requestPayload,
        Throwable $exception,
        Account|int|null $account,
    ): void {
        try {
            if ($account instanceof Account) {
                $accountId = $account->getKey();
                $accountId = is_numeric($accountId) ? (int) $accountId : null;
            } elseif (is_int($account)) {
                $accountId = $account;
            } else {
                try {
                    $accountId = $this->gate->accountFor($user, $account)->id;
                } catch (PaidAiUnavailable) {
                    $accountId = null;
                }
            }

            AiOperationAudit::query()->create([
                'operation_id' => $operationId,
                'account_id' => $accountId,
                'actor_user_id' => $user->id,
                'event' => AiAuditEvent::Prevented,
                'purpose' => $purpose,
                'provider' => $provider,
                'model' => $model,
                'request_hash' => $this->contentHash($requestPayload),
                'request_bytes' => strlen($requestPayload),
                'error_code' => 'account_policy',
                'exception_class' => $exception::class,
                'ip_hash' => $this->requestHash(request()->ip()),
                'user_agent_hash' => $this->requestHash(request()->userAgent()),
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            throw PaidAiUnavailable::auditUnavailable();
        }
    }

    private function requestHash(?string $value): ?string
    {
        return filled($value) ? hash_hmac('sha256', $value, (string) config('app.key')) : null;
    }

    private function contentHash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
