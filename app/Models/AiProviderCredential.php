<?php

namespace App\Models;

use Database\Factories\AiProviderCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id Legacy creator attribution only; never an authorization boundary.
 * @property int $account_id
 * @property string $provider
 * @property string $secret
 * @property string $fingerprint
 * @property string $last_four
 * @property Carbon|null $rotated_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'account_id', 'provider', 'secret', 'fingerprint', 'last_four', 'rotated_at', 'revoked_at'])]
#[Hidden(['secret'])]
class AiProviderCredential extends Model
{
    /** @use HasFactory<AiProviderCredentialFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'rotated_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
