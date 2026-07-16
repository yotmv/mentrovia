<?php

namespace App\Models;

use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use Database\Factories\AiOperationAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property AiAuditEvent $event
 * @property AiModelPurpose|null $purpose
 * @property Carbon $occurred_at
 * @property string|null $cost_usd
 */
#[Fillable(['operation_id', 'account_id', 'actor_user_id', 'event', 'purpose', 'provider', 'model', 'credential_fingerprint', 'request_hash', 'request_bytes', 'output_hash', 'output_bytes', 'input_tokens', 'output_tokens', 'cost_usd', 'changed_fields', 'before_fingerprint', 'after_fingerprint', 'error_code', 'exception_class', 'ip_hash', 'user_agent_hash', 'occurred_at'])]
class AiOperationAudit extends Model
{
    /** @use HasFactory<AiOperationAuditFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('AI audit records are immutable.'));
        static::deleting(fn (): never => throw new LogicException('AI audit records are permanent.'));
    }

    protected function casts(): array
    {
        return [
            'event' => AiAuditEvent::class,
            'purpose' => AiModelPurpose::class,
            'cost_usd' => 'decimal:6',
            'changed_fields' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
