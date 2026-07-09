<?php

namespace App\Services\Branding;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextGenerationRequest;
use App\Enums\TextGenerationRole;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;

class BrandKitGenerator
{
    /**
     * Regenerable brand kit sections and the JSON shape each one expects.
     *
     * @var array<string, string>
     */
    public const Sections = [
        'name_ideas' => '5-8 short, distinct business name ideas as strings',
        'tagline_options' => '5-8 taglines as strings',
        'positioning' => 'a 2-3 sentence positioning statement string',
        'tone_voice' => '3-6 strings, each a voice trait with a short practical explanation',
        'color_palette' => '4-6 objects with name, hex, usage, role, and prominence; role is a short label like background, foreground, primary, surface, border, signal, or accent; prominence is "dominant" for the 2-3 load-bearing colors and "supporting" for occasional accents',
        'font_notes' => '2-4 practical typeface directions as strings covering display/headline, body, and UI/label choices; do not default to Inter, Roboto, Arial, or system fonts',
        'image_prompts' => '4-6 logo/brand image generation prompts as strings',
        'brand_board_prompt' => 'one complete production-ready image-generation prompt string for a single 3840 x 2160 pixel 16:9 brand board: two large marketing-site page mockups (the homepage in the left 40 percent, a different supporting page in the middle 40 percent) plus a right-side 20 percent design-system rail documenting only typography directions and hierarchical color values with dominant colors as large swatches and supporting accents as smaller chips; the prompt must state brand positioning, audience, 3-5 tone adjectives, one memorable concept-specific aesthetic direction, both page structures with generous whitespace, and explicit constraints against generic gradients, cramped layouts, extra panels, and default fonts',
        'social_bios' => 'objects with platform and bio for instagram, facebook, google_business, and x',
    ];

    public function __construct(
        protected TextRoleGenerator $generator,
    ) {}

    /**
     * Generate a new brand kit version for the business and persist it.
     */
    public function generate(User $user, Business $business): BrandKit
    {
        $business->loadMissing('profileAnswers');

        $result = $this->generator->generate(TextGenerationRequest::make(
            TextGenerationRole::BrandCopy,
            $this->prompt(),
            [
                'business' => $this->businessContext($business),
                'style_reference' => $this->styleReference(),
            ],
        ));

        $payload = $this->decodePayload($result->text);

        return BrandKit::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'version' => $this->nextVersion($business),
            'name_ideas' => $this->strings(Arr::get($payload, 'name_ideas')),
            'tagline_options' => $this->strings(Arr::get($payload, 'tagline_options')),
            'positioning' => $this->stringOrNull(Arr::get($payload, 'positioning')),
            'tone_voice' => $this->strings(Arr::get($payload, 'tone_voice')),
            'color_palette' => $this->colorPalette(Arr::get($payload, 'color_palette')),
            'font_notes' => $this->strings(Arr::get($payload, 'font_notes')),
            'image_prompts' => $this->strings(Arr::get($payload, 'image_prompts')),
            'brand_board_prompt' => $this->stringOrNull(Arr::get($payload, 'brand_board_prompt')),
            'social_bios' => $this->socialBios(Arr::get($payload, 'social_bios')),
            'provider' => $result->provider,
            'model' => $result->model,
            'config_version' => $result->configVersion,
            'raw_response' => $payload,
            'generated_at' => now(),
        ]);
    }

    /**
     * Regenerate one section of an existing kit in place, keeping the version.
     */
    public function regenerateSection(BrandKit $kit, string $section): BrandKit
    {
        if (! array_key_exists($section, self::Sections)) {
            throw BrandKitGenerationException::unknownSection($section);
        }

        $business = $kit->business()->with('profileAnswers')->firstOrFail();

        $result = $this->generator->generate(TextGenerationRequest::make(
            TextGenerationRole::BrandCopy,
            $this->sectionPrompt($section),
            [
                'business' => $this->businessContext($business),
                'style_reference' => $this->styleReference(),
                'current_brand_kit' => $this->kitContext($kit),
            ],
        ));

        $payload = $this->decodePayload($result->text);
        $value = $this->sanitizeSection($section, Arr::get($payload, $section, $payload));

        if ($value === [] || $value === null) {
            throw BrandKitGenerationException::emptySection($section);
        }

        $kit->update([
            $section => $value,
            'preferences' => $this->prunedPreferences($kit, $section, $value),
            'provider' => $result->provider,
            'model' => $result->model,
            'config_version' => $result->configVersion,
            'generated_at' => now(),
        ]);

        return $kit->refresh();
    }

    protected function sectionPrompt(string $section): string
    {
        return 'Regenerate one section of an existing brand kit for this small business using the provided business '
            .'profile, style reference, and current brand kit. Produce fresh options that do not repeat the current '
            ."section content. Return only JSON with a single key \"{$section}\" containing "
            .self::Sections[$section].'. '
            .'Ground every idea in the actual business profile and do not invent credentials, awards, or claims the '
            .'profile does not support.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function kitContext(BrandKit $kit): array
    {
        return [
            'version' => $kit->version,
            'name_ideas' => $kit->name_ideas,
            'tagline_options' => $kit->tagline_options,
            'positioning' => $kit->positioning,
            'tone_voice' => $kit->tone_voice,
            'color_palette' => $kit->color_palette,
            'font_notes' => $kit->font_notes,
            'image_prompts' => $kit->image_prompts,
            'brand_board_prompt' => $kit->brand_board_prompt,
            'social_bios' => $kit->social_bios,
        ];
    }

    /**
     * @return array<int|string, mixed>|string|null
     */
    protected function sanitizeSection(string $section, mixed $value): array|string|null
    {
        return match ($section) {
            'positioning', 'brand_board_prompt' => $this->stringOrNull($value),
            'color_palette' => $this->colorPalette($value),
            'social_bios' => $this->socialBios($value),
            default => $this->strings($value),
        };
    }

    /**
     * Drop saved preferences that no longer exist in the regenerated section.
     *
     * @param  array<int|string, mixed>|string|null  $value
     * @return array<string, string>|null
     */
    protected function prunedPreferences(BrandKit $kit, string $section, array|string|null $value): ?array
    {
        $preferences = $kit->preferences;

        if ($preferences === null) {
            return null;
        }

        $preferenceKey = match ($section) {
            'name_ideas' => 'name',
            'tagline_options' => 'tagline',
            'color_palette' => 'color',
            default => null,
        };

        if ($preferenceKey === null || ! array_key_exists($preferenceKey, $preferences)) {
            return $preferences;
        }

        $options = $section === 'color_palette' && is_array($value)
            ? array_column($value, 'hex')
            : (is_array($value) ? $value : []);

        if (! in_array($preferences[$preferenceKey], $options, true)) {
            unset($preferences[$preferenceKey]);
        }

        return $preferences === [] ? null : $preferences;
    }

    protected function prompt(): string
    {
        $sections = collect(self::Sections)
            ->map(fn (string $spec, string $key): string => "{$key} ({$spec})")
            ->implode('; ');

        return 'Create a starter brand kit for this small business from the provided business profile and style reference. '
            ."Return only JSON with these keys: {$sections}. "
            .'Ground every idea in the actual business profile: industry, location, and customers. '
            .'Do not invent credentials, awards, years in business, or claims the profile does not support. '
            .'If the business already has a name, keep name_ideas as refinements or trade-name variations of it.';
    }

    /**
     * @return array<string, mixed>
     */
    protected function businessContext(Business $business): array
    {
        return [
            'display_name' => $business->displayName(),
            'existing_name' => $business->name,
            'desired_name' => $business->desired_name,
            'stage' => $business->stage?->value,
            'legal_structure' => $business->legal_structure->value,
            'industry' => $business->industry,
            'city' => $business->city,
            'county' => $business->county,
            'state' => $business->state,
            'location_type' => $business->location_type->value,
            'owner_count' => $business->owner_count,
            'employee_count' => $business->employee_count,
            'profile_answers' => $business->profileAnswers
                ->mapWithKeys(fn ($answer): array => [$answer->question_key => $answer->answer_value])
                ->all(),
        ];
    }

    /**
     * Visual and copy direction drawn from `docs/sample-static-site`.
     *
     * @return array<string, mixed>
     */
    protected function styleReference(): array
    {
        return [
            'reference' => 'Mentrovia sample static site (docs/sample-static-site)',
            'visual_direction' => 'Warm, grounded, and credible: a cream/paper background, near-black ink text, one deep natural primary color, and two or three muted accent colors. Soft shadows and generous whitespace over loud gradients.',
            'example_palette' => [
                ['name' => 'Ink', 'hex' => '#101828', 'usage' => 'Headings and body text'],
                ['name' => 'Cream', 'hex' => '#FFF8EE', 'usage' => 'Page background'],
                ['name' => 'Moss', 'hex' => '#2F6B4F', 'usage' => 'Primary buttons and highlights'],
                ['name' => 'Gold', 'hex' => '#C99A3A', 'usage' => 'Small accents and badges'],
                ['name' => 'Rust', 'hex' => '#B85C38', 'usage' => 'Occasional warm accent'],
            ],
            'copy_direction' => 'Plainspoken and concrete. Lead with what the business does and who it serves. No vague superlatives, no inflated urgency, no machine-sounding filler.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodePayload(string $text): array
    {
        foreach ($this->jsonObjectCandidates($text) as $json) {
            try {
                $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($payload) && $payload !== [] && ! array_is_list($payload)) {
                return $payload;
            }
        }

        throw BrandKitGenerationException::unstructuredResponse();
    }

    /**
     * Extract complete JSON objects while tolerating explanatory prose and Markdown fences.
     *
     * @return array<int, string>
     */
    protected function jsonObjectCandidates(string $text): array
    {
        $candidates = [];
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($end = $start; $end < $length; $end++) {
                $character = $text[$end];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($character === '\\') {
                        $escaped = true;
                    } elseif ($character === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($character === '"') {
                    $inString = true;

                    continue;
                }

                if ($character === '{') {
                    $depth++;
                } elseif ($character === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $candidates[] = substr($text, $start, $end - $start + 1);

                        break;
                    }
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    protected function nextVersion(Business $business): int
    {
        return ((int) BrandKit::query()->whereBelongsTo($business)->max('version')) + 1;
    }

    /**
     * @return array<int, string>
     */
    protected function strings(mixed $value): array
    {
        return collect(Arr::wrap($value))
            ->filter(fn (mixed $item): bool => is_string($item) && filled($item))
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all();
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && filled($value) ? trim($value) : null;
    }

    /**
     * @return array<int, array{name: string, hex: string, usage: string, role: string, prominence: string}>
     */
    protected function colorPalette(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $hex = strtoupper($this->stringOrNull(Arr::get($entry, 'hex')) ?? '');
                if (! Str::startsWith($hex, '#')) {
                    $hex = '#'.$hex;
                }

                if (! preg_match('/^#[0-9A-F]{6}$/', $hex)) {
                    return null;
                }

                return [
                    'name' => $this->stringOrNull(Arr::get($entry, 'name')) ?? $hex,
                    'hex' => $hex,
                    'usage' => $this->stringOrNull(Arr::get($entry, 'usage')) ?? '',
                    'role' => $this->stringOrNull(Arr::get($entry, 'role')) ?? '',
                    'prominence' => Arr::get($entry, 'prominence') === 'dominant' ? 'dominant' : 'supporting',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Accepts either a list of {platform, bio} objects or a platform => bio map.
     *
     * @return array<int, array{platform: string, bio: string}>
     */
    protected function socialBios(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function (mixed $entry, int|string $key): ?array {
                if (is_string($entry) && is_string($key) && filled($entry)) {
                    return ['platform' => $key, 'bio' => trim($entry)];
                }

                if (! is_array($entry)) {
                    return null;
                }

                $platform = $this->stringOrNull(Arr::get($entry, 'platform'));
                $bio = $this->stringOrNull(Arr::get($entry, 'bio'));

                return $platform !== null && $bio !== null
                    ? ['platform' => $platform, 'bio' => $bio]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
