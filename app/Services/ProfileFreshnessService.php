<?php

namespace App\Services;

use App\Enums\ProfileFreshness;
use App\Models\AdvertisingKit;
use App\Models\AgentConversationMessage;
use App\Models\BrandKit;
use App\Models\Business;
use App\Services\Branding\BrandKitGenerator;

class ProfileFreshnessService
{
    public function __construct(private BusinessProfileContext $contexts) {}

    public function brandSection(BrandKit $kit, Business $business, string $section): ProfileFreshness
    {
        $fingerprint = $kit->marketing_context_fingerprints[$section] ?? null;

        if (! is_string($fingerprint) || $fingerprint === '') {
            return ProfileFreshness::Unknown;
        }

        return hash_equals($fingerprint, $this->contexts->marketingFingerprint($business))
            ? ProfileFreshness::Current
            : ProfileFreshness::Stale;
    }

    public function brand(BrandKit $kit, Business $business): ProfileFreshness
    {
        $freshness = collect(array_keys(BrandKitGenerator::Sections))
            ->map(fn (string $section): ProfileFreshness => $this->brandSection($kit, $business, $section));

        if ($freshness->contains(ProfileFreshness::Stale)) {
            return ProfileFreshness::Stale;
        }

        return $freshness->contains(ProfileFreshness::Unknown)
            ? ProfileFreshness::Unknown
            : ProfileFreshness::Current;
    }

    public function advertising(AdvertisingKit $kit, Business $business, ?BrandKit $brandKit): ProfileFreshness
    {
        if (! is_string($kit->profile_fingerprint) || $kit->profile_fingerprint === '') {
            return ProfileFreshness::Unknown;
        }

        if (! hash_equals($kit->profile_fingerprint, $this->contexts->marketingFingerprint($business))) {
            return ProfileFreshness::Stale;
        }

        if ($kit->brand_kit_id === null && $kit->brand_content_fingerprint === null) {
            return $brandKit instanceof BrandKit ? ProfileFreshness::Stale : ProfileFreshness::Current;
        }

        if (! is_string($kit->brand_content_fingerprint) || ! $brandKit instanceof BrandKit) {
            return ProfileFreshness::Stale;
        }

        $currentBrandFingerprint = $this->contexts->brandContentFingerprint($brandKit);

        return is_string($currentBrandFingerprint) && hash_equals($kit->brand_content_fingerprint, $currentBrandFingerprint)
            ? ProfileFreshness::Current
            : ProfileFreshness::Stale;
    }

    public function advisor(AgentConversationMessage $message, Business $business): ProfileFreshness
    {
        $fingerprint = data_get($message->meta, 'profile_context.fingerprint');

        if (! is_string($fingerprint) || $fingerprint === '') {
            return ProfileFreshness::Unknown;
        }

        return hash_equals($fingerprint, $this->contexts->advisorFingerprint($business))
            ? ProfileFreshness::Current
            : ProfileFreshness::Stale;
    }
}
