<?php

namespace App\Models;

use App\Enums\AccountRole;
use Database\Factories\AccountInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $account_id
 * @property int|null $invited_by_user_id
 * @property int|null $accepted_by_user_id
 * @property string $email
 * @property AccountRole $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['account_id', 'invited_by_user_id', 'accepted_by_user_id', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class AccountInvitation extends Model
{
    /** @use HasFactory<AccountInvitationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (AccountInvitation $invitation): void {
            $invitation->public_id ??= bin2hex(random_bytes(20));
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function tokenMatches(#[\SensitiveParameter] string $plainTextToken): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $plainTextToken));
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
