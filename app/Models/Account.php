<?php

namespace App\Models;

use App\Enums\AccountRole;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Billable;

/**
 * @property int $id
 * @property string $name
 * @property Carbon|null $erasure_started_at
 * @property string|null $stripe_id
 * @property string|null $pm_type
 * @property string|null $pm_last_four
 * @property Carbon|null $trial_ends_at
 * @property string|null $billing_checkout_token
 * @property string|null $billing_checkout_session_id
 * @property Carbon|null $billing_checkout_expires_at
 * @property string|null $billing_checkout_status
 * @property string|null $billing_checkout_interval
 * @property string|null $billing_checkout_price_fingerprint
 */
#[Fillable(['name'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use Billable, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'erasure_started_at' => 'datetime',
            'trial_ends_at' => 'immutable_datetime',
            'billing_checkout_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsToMany<User, $this, AccountMembership, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(AccountMembership::class)
            ->as('membership')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasOne<Business, $this> */
    public function business(): HasOne
    {
        return $this->hasOne(Business::class);
    }

    /** @return HasOne<OnboardingDraft, $this> */
    public function onboardingDraft(): HasOne
    {
        return $this->hasOne(OnboardingDraft::class);
    }

    /** @return HasOneThrough<RoadmapPlan, Business, $this> */
    public function roadmapPlan(): HasOneThrough
    {
        return $this->hasOneThrough(
            RoadmapPlan::class,
            Business::class,
            'account_id',
            'business_id',
        );
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return HasMany<AccountInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(AccountInvitation::class);
    }

    /** @return HasOne<AccountEntitlement, $this> */
    public function entitlement(): HasOne
    {
        return $this->hasOne(AccountEntitlement::class);
    }

    /** @return HasOne<AiAccountSetting, $this> */
    public function aiAccountSetting(): HasOne
    {
        return $this->hasOne(AiAccountSetting::class);
    }

    /** @return HasMany<AiProviderCredential, $this> */
    public function aiProviderCredentials(): HasMany
    {
        return $this->hasMany(AiProviderCredential::class);
    }

    /** @return HasMany<AiModelPreference, $this> */
    public function aiModelPreferences(): HasMany
    {
        return $this->hasMany(AiModelPreference::class);
    }

    public function roleFor(User $user): ?AccountRole
    {
        $membership = $this->members()->whereKey($user->id)->first()?->getRelation('membership');

        return $membership instanceof AccountMembership ? $membership->role : null;
    }

    public function isMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    public function hasRole(User $user, AccountRole ...$roles): bool
    {
        $role = $this->roleFor($user);

        return $role !== null && in_array($role, $roles, true);
    }

    /** @return array<string, string> */
    public function stripeMetadata(): array
    {
        return ['account_id' => (string) $this->id];
    }

    public function stripeEmail(): ?string
    {
        return $this->members()
            ->wherePivot('role', AccountRole::Owner->value)
            ->value('email');
    }
}
