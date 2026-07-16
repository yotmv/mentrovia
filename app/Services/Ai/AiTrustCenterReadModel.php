<?php

namespace App\Services\Ai;

use App\Enums\AccountCapability;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Models\Account;
use App\Models\AiAccountSetting;
use App\Models\AiModelPreference;
use App\Models\AiOperationAudit;
use App\Models\User;
use App\Services\Accounts\AccountEntitlementGate;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AiTrustCenterReadModel
{
    private const ACTOR_OPTION_LIMIT = 100;

    public function __construct(private AccountEntitlementGate $entitlements) {}

    /**
     * @param  array<string, int|string|null>  $filters
     * @return LengthAwarePaginator<int, AiOperationAudit>
     */
    public function timeline(Account $account, array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($account, $filters)
            ->orderByDesc('ai_operation_audits.occurred_at')
            ->orderByDesc('ai_operation_audits.id')
            ->paginate(25);
    }

    /**
     * @param  array<string, int|string|null>  $filters
     * @return Builder<AiOperationAudit>
     */
    public function filteredQuery(Account $account, array $filters): Builder
    {
        $query = AiOperationAudit::query()
            ->leftJoin('users as audit_actor', 'audit_actor.id', '=', 'ai_operation_audits.actor_user_id')
            ->where('ai_operation_audits.account_id', $account->id)
            ->select([
                'ai_operation_audits.id',
                'ai_operation_audits.operation_id',
                'ai_operation_audits.account_id',
                'ai_operation_audits.actor_user_id',
                'ai_operation_audits.event',
                'ai_operation_audits.purpose',
                'ai_operation_audits.provider',
                'ai_operation_audits.model',
                'ai_operation_audits.credential_fingerprint',
                'ai_operation_audits.request_hash',
                'ai_operation_audits.before_fingerprint',
                'ai_operation_audits.after_fingerprint',
                'ai_operation_audits.cost_usd',
                'ai_operation_audits.changed_fields',
                'ai_operation_audits.error_code',
                'ai_operation_audits.occurred_at',
                'audit_actor.name as actor_name',
                'audit_actor.email as actor_email',
            ]);

        if (filled($filters['event'] ?? null)) {
            $query->where('ai_operation_audits.event', $filters['event']);
        }

        if (filled($filters['outcome'] ?? null)) {
            $query->whereIn('ai_operation_audits.event', $this->eventsForOutcome((string) $filters['outcome']));
        }

        if (is_numeric($filters['actor'] ?? null)) {
            $query->where('ai_operation_audits.actor_user_id', (int) $filters['actor']);
        }

        foreach (['purpose', 'provider'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where('ai_operation_audits.'.$field, $filters[$field]);
            }
        }

        if (filled($filters['model'] ?? null)) {
            $model = addcslashes((string) $filters['model'], '\\%_');
            $query->where('ai_operation_audits.model', 'like', '%'.$model.'%');
        }

        if (filled($filters['operation_id'] ?? null)) {
            $query->where('ai_operation_audits.operation_id', $filters['operation_id']);
        }

        if (filled($filters['date_from'] ?? null)) {
            $query->where('ai_operation_audits.occurred_at', '>=', CarbonImmutable::parse((string) $filters['date_from'], 'UTC')->startOfDay());
        }

        if (filled($filters['date_to'] ?? null)) {
            $query->where('ai_operation_audits.occurred_at', '<=', CarbonImmutable::parse((string) $filters['date_to'], 'UTC')->endOfDay());
        }

        return $query;
    }

    /**
     * @param  array<string, int|string|null>  $filters
     * @return Generator<int, AiOperationAudit>
     */
    public function exportRows(Account $account, array $filters, int $cutoffId, int $chunkSize): Generator
    {
        $baseQuery = $this->filteredQuery($account, $filters)
            ->where('ai_operation_audits.id', '<=', $cutoffId);
        $lastOccurredAt = null;
        $lastId = null;

        while (true) {
            $query = clone $baseQuery;

            if (is_string($lastOccurredAt)) {
                $query->where(function (Builder $continuation) use ($lastOccurredAt, $lastId): void {
                    $continuation
                        ->where('ai_operation_audits.occurred_at', '<', $lastOccurredAt)
                        ->orWhere(function (Builder $sameTimestamp) use ($lastOccurredAt, $lastId): void {
                            $sameTimestamp
                                ->where('ai_operation_audits.occurred_at', '=', $lastOccurredAt)
                                ->where('ai_operation_audits.id', '<', $lastId);
                        });
                });
            }

            $rows = $query
                ->orderByDesc('ai_operation_audits.occurred_at')
                ->orderByDesc('ai_operation_audits.id')
                ->limit($chunkSize)
                ->get();

            foreach ($rows as $row) {
                yield $row;
            }

            if ($rows->count() < $chunkSize) {
                return;
            }

            $last = $rows->last();

            if (! $last instanceof AiOperationAudit) {
                throw new RuntimeException('AI audit export keyset columns are unavailable.');
            }

            $rawOccurredAt = $last->getRawOriginal('occurred_at');

            if (! is_string($rawOccurredAt)) {
                throw new RuntimeException('AI audit export keyset columns are unavailable.');
            }

            $lastOccurredAt = $rawOccurredAt;
            $lastId = $last->id;
        }
    }

    /** @return array<int, array{id: int, name: string|null}> */
    public function actors(Account $account): array
    {
        $auditTable = (new AiOperationAudit)->getTable();
        $recentActors = DB::table($auditTable)
            ->where('account_id', $account->id)
            ->whereNotNull('actor_user_id')
            ->selectRaw('actor_user_id, MAX(id) as last_audit_id')
            ->groupBy('actor_user_id')
            ->orderByDesc('last_audit_id')
            ->orderByDesc('actor_user_id')
            ->limit(self::ACTOR_OPTION_LIMIT)
            ->get();
        $actorIds = $recentActors
            ->map(fn (object $actor): int => (int) $actor->actor_user_id)
            ->all();
        $actorsById = User::query()
            ->whereIn('id', $actorIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        return $recentActors->map(function (object $actor) use ($actorsById): array {
            $actorId = (int) $actor->actor_user_id;
            $user = $actorsById->get($actorId);

            return [
                'id' => $actorId,
                'name' => $user instanceof User ? $user->name : null,
            ];
        })->values()->all();
    }

    /**
     * @return array{actual_cost: float, reserved_cost: float, limit: float|null, remaining: float|null, reset_at: CarbonImmutable, concurrency_used: int, concurrency_limit: int}
     */
    public function usage(Account $account): array
    {
        $now = CarbonImmutable::now('UTC');
        $settings = $account->aiAccountSetting()->first();
        $actual = (float) AiOperationAudit::query()
            ->whereBelongsTo($account)
            ->where('event', AiAuditEvent::Succeeded->value)
            ->where('occurred_at', '>=', $now->startOfMonth())
            ->sum('cost_usd');
        $active = AiOperationAudit::query()
            ->whereBelongsTo($account)
            ->where('event', AiAuditEvent::Started->value)
            ->where('occurred_at', '>=', $now->subMinutes(15))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('ai_operation_audits as completion')
                    ->whereColumn('completion.operation_id', 'ai_operation_audits.operation_id')
                    ->whereColumn('completion.account_id', 'ai_operation_audits.account_id')
                    ->whereIn('completion.event', [AiAuditEvent::Succeeded->value, AiAuditEvent::Failed->value]);
            })
            ->selectRaw('COALESCE(SUM(cost_usd), 0) as reserved_cost, COUNT(*) as concurrency_used')
            ->first();
        $reserved = (float) ($active?->getAttribute('reserved_cost') ?? 0);
        $limit = $settings?->monthly_usd_limit !== null ? (float) $settings->monthly_usd_limit : null;

        return [
            'actual_cost' => $actual,
            'reserved_cost' => $reserved,
            'limit' => $limit,
            'remaining' => $limit !== null ? max(0, $limit - $actual - $reserved) : null,
            'reset_at' => $now->addMonthNoOverflow()->startOfMonth(),
            'concurrency_used' => (int) ($active?->getAttribute('concurrency_used') ?? 0),
            'concurrency_limit' => $settings instanceof AiAccountSetting ? $settings->max_concurrency : 1,
        ];
    }

    /** @return array<int, array{purpose: string, mode: string, route: string, models: array<int, string>}> */
    public function routing(Account $account): array
    {
        $account->loadMissing('entitlement');
        $settings = $account->aiAccountSetting()->first();
        $preferences = $account->aiModelPreferences()->get()->keyBy(
            fn (AiModelPreference $preference): string => $preference->purpose->value,
        );
        $hasActiveCredential = $account->aiProviderCredentials()
            ->where('provider', 'openrouter')
            ->whereNull('revoked_at')
            ->exists();
        $route = $this->effectiveRoute(
            $settings,
            $hasActiveCredential,
            $this->entitlements->allows($account, AccountCapability::HostedAi),
        );
        $routing = [];

        foreach (AiModelPurpose::cases() as $purpose) {
            $preference = $preferences->get($purpose->value);
            $mode = $purpose === AiModelPurpose::Auto
                ? AiModelMode::Auto
                : ($preference instanceof AiModelPreference ? $preference->mode : AiModelMode::Auto);
            $models = $mode === AiModelMode::Custom
                && $preference instanceof AiModelPreference
                && ($preference->model_ids ?? []) !== []
                ? array_values($preference->model_ids)
                : array_values(config('account-ai.auto_models.'.$purpose->value, []));

            $routing[] = [
                'purpose' => $purpose->value,
                'mode' => $mode->value,
                'route' => $route,
                'models' => $route === 'disabled' ? [] : $models,
            ];
        }

        return $routing;
    }

    /** @return array<int, string> */
    private function eventsForOutcome(string $outcome): array
    {
        return array_values(array_map(
            fn (AiAuditEvent $event): string => $event->value,
            array_filter(AiAuditEvent::cases(), fn (AiAuditEvent $event): bool => $event->outcome() === $outcome),
        ));
    }

    private function effectiveRoute(?AiAccountSetting $settings, bool $hasActiveCredential, bool $hasCapability): string
    {
        if (! $hasCapability || ($settings instanceof AiAccountSetting && ! $settings->paid_ai_enabled)) {
            return 'disabled';
        }

        if ($settings?->byok_enabled && $hasActiveCredential) {
            return 'byok';
        }

        if ($settings === null || $settings->hosted_ai_enabled) {
            return 'hosted';
        }

        return 'disabled';
    }
}
