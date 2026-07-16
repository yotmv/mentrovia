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
 * @property int|null $user_id
 * @property int $version
 * @property int|null $profile_revision
 * @property string|null $profile_fingerprint
 * @property array<string, int>|null $section_profile_revisions
 * @property array<string, string>|null $marketing_context_fingerprints
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
    'business_id', 'user_id', 'version', 'profile_revision', 'profile_fingerprint',
    'section_profile_revisions', 'marketing_context_fingerprints', 'name_ideas', 'tagline_options',
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
            'profile_revision' => 'integer',
            'section_profile_revisions' => 'array',
            'marketing_context_fingerprints' => 'array',
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
