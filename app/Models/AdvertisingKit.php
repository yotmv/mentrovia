<?php

namespace App\Models;

use Database\Factories\AdvertisingKitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $user_id
 * @property int|null $brand_kit_id
 * @property int $version
 * @property array<int, string> $ad_angles
 * @property array<int, array{headline: string, body: string, cta: string}> $facebook_instagram_copy
 * @property array<int, array{headline: string, description: string}> $google_ads
 * @property array<int, string> $social_posts
 * @property array{headline: string, subheadline: string, bullets: array<int, string>, call_to_action: string}|null $flyer_copy
 * @property array<int, string> $image_prompts
 * @property array<int, array{section: string, content: string}> $landing_page_outline
 * @property array<int, array{week: int, focus: string, actions: array<int, string>}> $thirty_day_plan
 * @property string $provider
 * @property string $model
 * @property string $config_version
 * @property array<string, mixed>|null $raw_response
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'business_id', 'user_id', 'brand_kit_id', 'version', 'ad_angles',
    'facebook_instagram_copy', 'google_ads', 'social_posts', 'flyer_copy',
    'image_prompts', 'landing_page_outline', 'thirty_day_plan',
    'provider', 'model', 'config_version', 'raw_response', 'generated_at',
])]
class AdvertisingKit extends Model
{
    /** @use HasFactory<AdvertisingKitFactory> */
    use HasFactory;

    protected $attributes = [
        'version' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'ad_angles' => 'array',
            'facebook_instagram_copy' => 'array',
            'google_ads' => 'array',
            'social_posts' => 'array',
            'flyer_copy' => 'array',
            'image_prompts' => 'array',
            'landing_page_outline' => 'array',
            'thirty_day_plan' => 'array',
            'raw_response' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The brand kit whose context grounded this advertising kit, when one existed.
     *
     * @return BelongsTo<BrandKit, $this>
     */
    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }
}
