<?php

namespace App\Models;

use App\Enums\BusinessOnboardingTrack;
use Database\Factories\OnboardingDraftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $account_id
 * @property BusinessOnboardingTrack $track
 * @property int $current_step
 * @property array<string, bool|int|string|null> $payload
 * @property int $schema_version
 * @property int $revision
 * @property int|null $last_saved_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'account_id', 'track', 'current_step', 'payload', 'schema_version', 'revision',
    'last_saved_by_user_id', 'expires_at',
])]
#[Hidden(['payload'])]
class OnboardingDraft extends Model
{
    /** @use HasFactory<OnboardingDraftFactory> */
    use HasFactory;

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<User, $this> */
    public function lastSavedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_saved_by_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'track' => BusinessOnboardingTrack::class,
            'current_step' => 'integer',
            'payload' => 'encrypted:array',
            'schema_version' => 'integer',
            'revision' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
