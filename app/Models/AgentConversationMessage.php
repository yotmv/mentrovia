<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int|null $user_id
 * @property string $agent
 * @property string $role
 * @property string $content
 * @property array<int, mixed>|null $attachments
 * @property array<int, mixed>|null $tool_calls
 * @property array<int, mixed>|null $tool_results
 * @property array<string, mixed>|null $usage
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'conversation_id', 'user_id', 'agent', 'role', 'content', 'attachments',
    'tool_calls', 'tool_results', 'usage', 'meta',
])]
class AgentConversationMessage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::creating(function (AgentConversationMessage $message): void {
            $message->id ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'usage' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AgentConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
