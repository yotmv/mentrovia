<?php

namespace App\Services\Advertising;

use App\Ai\Text\Contracts\TextRoleGenerator;
use App\Ai\Text\TextGenerationRequest;
use App\Enums\TextGenerationRole;
use App\Models\AdvertisingKit;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AdvertisingKitGenerator
{
    /**
     * Advertising kit sections and the JSON shape each one expects.
     *
     * @var array<string, string>
     */
    public const Sections = [
        'ad_angles' => '4-6 strings, each one ad angle stating the concrete customer problem or offer it leads with and why it fits this business',
        'facebook_instagram_copy' => '3-5 objects with headline, body, and cta for Facebook/Instagram ads; body is 1-3 short sentences that lead with the offer or problem',
        'google_ads' => '3-5 objects with headline (30 characters or fewer) and description (90 characters or fewer) as Google search ad concepts',
        'social_posts' => '4-6 ready-to-post organic social posts as strings, each standing alone without hashtag spam',
        'flyer_copy' => 'one object with headline, subheadline, bullets (3-5 short strings), and call_to_action for a printable flyer',
        'image_prompts' => '4-6 ad image generation prompt variants as strings, each a complete standalone prompt',
        'landing_page_outline' => '5-8 objects with section and content outlining one simple landing page from top to bottom',
        'thirty_day_plan' => '4 objects, one per week, with week (integer 1-4), focus, and actions (3-5 concrete strings a busy owner can actually do)',
    ];

    public function __construct(
        protected TextRoleGenerator $generator,
    ) {}

    /**
     * Generate a new advertising kit version for the business and persist it.
     */
    public function generate(User $user, Business $business): AdvertisingKit
    {
        $business->loadMissing('profileAnswers');

        $brandKit = $this->latestBrandKit($user, $business);

        $context = [
            'business' => $this->businessContext($business),
        ];

        if ($brandKit instanceof BrandKit) {
            $context['brand_kit'] = $this->brandKitContext($brandKit);
        }

        $result = $this->generator->generate(TextGenerationRequest::make(
            TextGenerationRole::AdCopy,
            $this->prompt($brandKit),
            $context,
        ));

        $payload = $this->decodePayload($result->text);

        $sections = [
            'ad_angles' => $this->strings(Arr::get($payload, 'ad_angles')),
            'facebook_instagram_copy' => $this->adVariants(Arr::get($payload, 'facebook_instagram_copy')),
            'google_ads' => $this->googleAds(Arr::get($payload, 'google_ads')),
            'social_posts' => $this->strings(Arr::get($payload, 'social_posts')),
            'flyer_copy' => $this->flyerCopy(Arr::get($payload, 'flyer_copy')),
            'image_prompts' => $this->strings(Arr::get($payload, 'image_prompts')),
            'landing_page_outline' => $this->landingPageOutline(Arr::get($payload, 'landing_page_outline')),
            'thirty_day_plan' => $this->thirtyDayPlan(Arr::get($payload, 'thirty_day_plan')),
        ];

        if (collect($sections)->every(fn (array|string|null $section): bool => $section === [] || $section === null)) {
            throw AdvertisingKitGenerationException::emptyKit();
        }

        return AdvertisingKit::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'brand_kit_id' => $brandKit?->id,
            'version' => $this->nextVersion($business),
            ...$sections,
            'provider' => $result->provider,
            'model' => $result->model,
            'config_version' => $result->configVersion,
            'raw_response' => $payload,
            'generated_at' => now(),
        ]);
    }

    protected function prompt(?BrandKit $brandKit): string
    {
        $sections = collect(self::Sections)
            ->map(fn (string $spec, string $key): string => "{$key} ({$spec})")
            ->implode('; ');

        $brandDirection = $brandKit instanceof BrandKit
            ? 'A brand kit is provided: keep names, tone, colors, and wording consistent with it, and prefer the '
                .'preferred name and tagline when they are set. '
            : 'No brand kit exists yet, so keep naming neutral and use the business profile name. ';

        return 'Create starter advertising outputs for this small business from the provided business profile. '
            ."Return only JSON with these keys: {$sections}. "
            .$brandDirection
            .'Ground every output in the actual business profile: industry, location, and customers. '
            .'Do not invent discounts, prices, credentials, review counts, or claims the profile does not support.';
    }

    protected function latestBrandKit(User $user, Business $business): ?BrandKit
    {
        return $business->brandKits()
            ->whereBelongsTo($user)
            ->orderByDesc('version')
            ->first();
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
     * The brand identity context ads should stay consistent with.
     *
     * @return array<string, mixed>
     */
    protected function brandKitContext(BrandKit $brandKit): array
    {
        return [
            'version' => $brandKit->version,
            'preferences' => $brandKit->preferences,
            'name_ideas' => $brandKit->name_ideas,
            'tagline_options' => $brandKit->tagline_options,
            'positioning' => $brandKit->positioning,
            'tone_voice' => $brandKit->tone_voice,
            'color_palette' => $brandKit->color_palette,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodePayload(string $text): array
    {
        $json = Str::of($text)
            ->trim()
            ->replaceMatches('/^```(?:json)?\s*/', '')
            ->replaceMatches('/\s*```$/', '')
            ->toString();

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw AdvertisingKitGenerationException::unstructuredResponse();
        }

        return $payload;
    }

    protected function nextVersion(Business $business): int
    {
        return ((int) AdvertisingKit::query()->whereBelongsTo($business)->max('version')) + 1;
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
     * @return array<int, array{headline: string, body: string, cta: string}>
     */
    protected function adVariants(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $headline = $this->stringOrNull(Arr::get($entry, 'headline'));
                $body = $this->stringOrNull(Arr::get($entry, 'body'));

                return $headline !== null && $body !== null
                    ? [
                        'headline' => $headline,
                        'body' => $body,
                        'cta' => $this->stringOrNull(Arr::get($entry, 'cta')) ?? '',
                    ]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{headline: string, description: string}>
     */
    protected function googleAds(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $headline = $this->stringOrNull(Arr::get($entry, 'headline'));
                $description = $this->stringOrNull(Arr::get($entry, 'description'));

                return $headline !== null && $description !== null
                    ? ['headline' => $headline, 'description' => $description]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{headline: string, subheadline: string, bullets: array<int, string>, call_to_action: string}|null
     */
    protected function flyerCopy(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $headline = $this->stringOrNull(Arr::get($value, 'headline'));

        if ($headline === null) {
            return null;
        }

        return [
            'headline' => $headline,
            'subheadline' => $this->stringOrNull(Arr::get($value, 'subheadline')) ?? '',
            'bullets' => $this->strings(Arr::get($value, 'bullets')),
            'call_to_action' => $this->stringOrNull(Arr::get($value, 'call_to_action')) ?? '',
        ];
    }

    /**
     * @return array<int, array{section: string, content: string}>
     */
    protected function landingPageOutline(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function (mixed $entry): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $section = $this->stringOrNull(Arr::get($entry, 'section'));

                return $section !== null
                    ? [
                        'section' => $section,
                        'content' => $this->stringOrNull(Arr::get($entry, 'content')) ?? '',
                    ]
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{week: int, focus: string, actions: array<int, string>}>
     */
    protected function thirtyDayPlan(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(function (mixed $entry, int|string $index): ?array {
                if (! is_array($entry)) {
                    return null;
                }

                $focus = $this->stringOrNull(Arr::get($entry, 'focus'));

                if ($focus === null) {
                    return null;
                }

                $week = Arr::get($entry, 'week');

                return [
                    'week' => is_numeric($week) ? (int) $week : (is_int($index) ? $index + 1 : 1),
                    'focus' => $focus,
                    'actions' => $this->strings(Arr::get($entry, 'actions')),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
