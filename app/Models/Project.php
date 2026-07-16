<?php

namespace App\Models;

use App\Enums\PhotoKind;
use App\Enums\ProjectPermission;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $account_id
 * @property string $name
 * @property Carbon $project_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'account_id', 'name', 'project_date'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function sharedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('permission')
            ->withTimestamps();
    }

    /** @return HasMany<ProjectInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function uploadedPhotos(): HasMany
    {
        return $this->photos()->where('kind', PhotoKind::Uploaded);
    }

    /**
     * @return HasMany<Photo, $this>
     */
    public function generatedPhotos(): HasMany
    {
        return $this->photos()->where('kind', PhotoKind::Generated);
    }

    /**
     * @return HasMany<PhotoGenerationBatch, $this>
     */
    public function generationBatches(): HasMany
    {
        return $this->hasMany(PhotoGenerationBatch::class);
    }

    /**
     * Scope the query to projects the given user owns or has been shared.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAccessibleTo(Builder $query, User $user, Account $account): Builder
    {
        return $query->where(function (Builder $query) use ($user, $account) {
            $query->where('account_id', $account->id)
                ->orWhereHas('sharedUsers', fn (Builder $query) => $query->whereKey($user->id));
        });
    }

    /**
     * Scope the query to projects matching the search term by name or date.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        return $query->when($term !== '', function (Builder $query) use ($term) {
            $query->where(function (Builder $query) use ($term) {
                $query->where('name', 'like', '%'.$term.'%');

                if (($date = self::parseSearchDate($term)) !== null) {
                    $query->orWhereDate('project_date', $date);
                }
            });
        });
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->account()->whereHas('members', fn (Builder $query): Builder => $query->whereKey($user->id))->exists();
    }

    public function isViewableBy(User $user): bool
    {
        return $this->isOwnedBy($user)
            || $this->sharedUsers()->whereKey($user->id)->exists();
    }

    public function isEditableBy(User $user): bool
    {
        return $this->isOwnedBy($user)
            || $this->sharedUsers()->whereKey($user->id)
                ->wherePivot('permission', ProjectPermission::Write->value)->exists();
    }

    /**
     * Interpret a search term as a date when possible.
     */
    private static function parseSearchDate(string $term): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$|^\d{1,2}\/\d{1,2}\/\d{4}$/', $term) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($term);
        } catch (\Throwable) {
            return null;
        }
    }
}
