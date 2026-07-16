<?php

namespace App\Models;

use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use Database\Factories\AiModelPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id Legacy creator attribution only; never an authorization boundary.
 * @property int $account_id
 * @property AiModelPurpose $purpose
 * @property AiModelMode $mode
 * @property array<int, string>|null $model_ids
 */
#[Fillable(['user_id', 'account_id', 'purpose', 'mode', 'model_ids'])]
class AiModelPreference extends Model
{
    /** @use HasFactory<AiModelPreferenceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purpose' => AiModelPurpose::class,
            'mode' => AiModelMode::class,
            'model_ids' => 'array',
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
}
