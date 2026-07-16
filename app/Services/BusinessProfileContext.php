<?php

namespace App\Services;

use App\Models\BrandKit;
use App\Models\Business;

final class BusinessProfileContext
{
    /** @var list<string> */
    private const array MARKETING_PROFILE_ANSWER_KEYS = [];

    public function __construct(
        private BusinessProfileSnapshot $snapshots,
        private BusinessProfileFingerprint $fingerprints,
    ) {}

    /** @return array<string, mixed> */
    public function marketing(Business $business): array
    {
        $business->loadMissing('profileAnswers');
        $facts = $this->snapshots->businessFacts($business);
        $context = [];

        foreach ([
            'name', 'desired_name', 'stage', 'legal_structure', 'industry', 'city', 'county',
            'state', 'location_type', 'owner_count', 'employee_count',
        ] as $field) {
            $context[$field] = $facts[$field] ?? null;
        }

        $context['display_name'] = $business->displayName();
        $context['profile_answers'] = $business->profileAnswers
            ->whereIn('question_key', self::MARKETING_PROFILE_ANSWER_KEYS)
            ->sortBy('question_key', SORT_STRING)
            ->mapWithKeys(fn ($answer): array => [$answer->question_key => $answer->answer_value])
            ->all();
        ksort($context['profile_answers'], SORT_STRING);

        return $context;
    }

    /** @return array<string, mixed> */
    public function advisor(Business $business): array
    {
        return $this->snapshots->capture($business);
    }

    public function marketingFingerprint(Business $business): string
    {
        return $this->fingerprints->make($this->marketing($business));
    }

    public function advisorFingerprint(Business $business): string
    {
        return $this->fingerprints->make($this->advisor($business));
    }

    public function brandContentFingerprint(?BrandKit $kit): ?string
    {
        if (! $kit instanceof BrandKit) {
            return null;
        }

        return $this->fingerprints->make([
            'version' => $kit->version,
            'preferences' => $kit->preferences,
            'name_ideas' => $kit->name_ideas,
            'tagline_options' => $kit->tagline_options,
            'positioning' => $kit->positioning,
            'tone_voice' => $kit->tone_voice,
            'color_palette' => $kit->color_palette,
            'font_notes' => $kit->font_notes,
            'image_prompts' => $kit->image_prompts,
            'brand_board_prompt' => $kit->brand_board_prompt,
            'social_bios' => $kit->social_bios,
        ]);
    }
}
