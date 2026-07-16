<?php

namespace App\Models;

use App\Notifications\QueuedResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property int|null $current_account_id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $account_erasure_started_at
 * @property string $password
 * @property bool $is_admin
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'account_erasure_started_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Account, $this, AccountMembership, 'membership'> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class)
            ->using(AccountMembership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return BelongsTo<Account, $this> */
    public function currentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'current_account_id');
    }

    public function belongsToAccount(Account $account): bool
    {
        return $this->accounts()->whereKey($account->id)->exists();
    }

    /**
     * The user's business profile (one business per user in v1).
     *
     * @return HasOne<Business, $this>
     */
    public function business(): HasOne
    {
        return $this->hasOne(Business::class);
    }

    /** @return HasOne<AiAccountSetting, $this> */
    public function aiAccountSetting(): HasOne
    {
        return $this->hasOne(AiAccountSetting::class, 'account_id', 'current_account_id');
    }

    /** @return HasMany<AiProviderCredential, $this> */
    public function aiProviderCredentials(): HasMany
    {
        return $this->hasMany(AiProviderCredential::class, 'account_id', 'current_account_id');
    }

    /** @return HasMany<AiModelPreference, $this> */
    public function aiModelPreferences(): HasMany
    {
        return $this->hasMany(AiModelPreference::class, 'account_id', 'current_account_id');
    }

    /**
     * Projects this user created.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Compliance validation runs requested in this user's context.
     *
     * @return HasMany<ValidationRun, $this>
     */
    public function validationRuns(): HasMany
    {
        return $this->hasMany(ValidationRun::class);
    }

    /**
     * Advisor Q&A conversations persisted through the Laravel AI SDK tables.
     *
     * @return HasMany<AgentConversation, $this>
     */
    public function advisorConversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class);
    }

    /**
     * Generated brand kit versions owned by this user.
     *
     * @return HasMany<BrandKit, $this>
     */
    public function brandKits(): HasMany
    {
        return $this->hasMany(BrandKit::class);
    }

    /**
     * @return HasMany<UserFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(UserFeedback::class);
    }

    /**
     * Generated advertising kit versions owned by this user.
     *
     * @return HasMany<AdvertisingKit, $this>
     */
    public function advertisingKits(): HasMany
    {
        return $this->hasMany(AdvertisingKit::class);
    }

    /**
     * Projects shared with this user by other owners.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function sharedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)
            ->withPivot('permission')
            ->withTimestamps();
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new QueuedResetPasswordNotification($token));
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
