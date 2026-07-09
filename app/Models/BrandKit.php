<?php

namespace App\Models;

use Database\Factories\BrandKitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_id
 * @property int $user_id
 * @property int $version
 * @property array<int, string> $name_ideas
 * @property array<int, string> $tagline_options
 * @property string|null $positioning
 * @property array<int, string> $tone_voice
 * @property array<int, array{name: string, hex: string, usage: string, role?: string, prominence?: string}> $color_palette
 * @property array<int, string> $font_notes
 * @property array<int, string> $image_prompts
 * @property string|null $brand_board_prompt
 * @property array<int, array{platform: string, bio: string}> $social_bios
 * @property array{name?: string, tagline?: string, color?: string}|null $preferences
 * @property string $provider
 * @property string $model
 * @property string $config_version
 * @property array<string, mixed>|null $raw_response
 * @property Carbon|null $generated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'business_id', 'user_id', 'version', 'name_ideas', 'tagline_options',
    'positioning', 'tone_voice', 'color_palette', 'font_notes',
    'image_prompts', 'brand_board_prompt', 'social_bios', 'preferences', 'provider', 'model', 'config_version',
    'raw_response', 'generated_at',
])]
class BrandKit extends Model
{
    /** @use HasFactory<BrandKitFactory> */
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
            'name_ideas' => 'array',
            'tagline_options' => 'array',
            'tone_voice' => 'array',
            'color_palette' => 'array',
            'font_notes' => 'array',
            'image_prompts' => 'array',
            'social_bios' => 'array',
            'preferences' => 'array',
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
}
