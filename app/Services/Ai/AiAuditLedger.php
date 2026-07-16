<?php

namespace App\Services\Ai;

use App\Enums\AiAuditEvent;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\User;
use Illuminate\Support\Str;

final class AiAuditLedger
{
    /**
     * @param  array<int, string>  $changedFields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function appendControlChange(
        Account $account,
        User $actor,
        AiAuditEvent $event,
        array $changedFields,
        array $before,
        array $after,
    ): AiOperationAudit {
        sort($changedFields);

        return $this->append([
            'operation_id' => (string) Str::uuid7(),
            'account_id' => $account->id,
            'actor_user_id' => $actor->id,
            'event' => $event,
            'changed_fields' => $changedFields,
            'before_fingerprint' => $this->fingerprint($before),
            'after_fingerprint' => $this->fingerprint($after),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function append(array $attributes): AiOperationAudit
    {
        return AiOperationAudit::query()->create([
            ...$attributes,
            'ip_hash' => $attributes['ip_hash'] ?? $this->requestHash(request()->ip()),
            'user_agent_hash' => $attributes['user_agent_hash'] ?? $this->requestHash(request()->userAgent()),
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }

    /** @param array<string, mixed> $value */
    public function fingerprint(array $value): string
    {
        return hash_hmac(
            'sha256',
            json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            (string) config('app.key'),
        );
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private function normalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }

    private function requestHash(?string $value): ?string
    {
        return filled($value)
            ? hash_hmac('sha256', $value, (string) config('app.key'))
            : null;
    }
}
