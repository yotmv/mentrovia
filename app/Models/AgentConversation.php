<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int|null $user_id
 * @property int|null $account_id
 * @property string $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'account_id', 'title'])]
class AgentConversation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public function getTable(): string
    {
        return config('ai.conversations.tables.conversations', parent::getTable());
    }

    protected static function booted(): void
    {
        static::creating(function (AgentConversation $conversation): void {
            $conversation->id ??= (string) Str::uuid();

            if ($conversation->account_id === null && $conversation->user_id !== null) {
                $accountId = User::query()->whereKey($conversation->user_id)->value('current_account_id');

                if (is_numeric($accountId)) {
                    $conversation->account_id = (int) $accountId;
                }
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<AgentConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentConversationMessage::class, 'conversation_id');
    }
}
