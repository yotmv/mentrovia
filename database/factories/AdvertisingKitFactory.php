<?php

namespace Database\Factories;

use App\Models\AdvertisingKit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdvertisingKit>
 */
class AdvertisingKitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'brand_kit_id' => null,
            'version' => 1,
            'ad_angles' => [
                'Lead with the one-crew promise: the people who measure are the people who install.',
                'Lead with turnaround time for homeowners mid-renovation.',
            ],
            'facebook_instagram_copy' => [
                ['headline' => 'One crew, start to finish', 'body' => 'We measure, cut, and install your counters ourselves. No handoffs, no surprises.', 'cta' => 'Get a quote'],
            ],
            'google_ads' => [
                ['headline' => 'Local Countertop Install', 'description' => 'Measured, cut, and installed by one local crew. Free in-home quotes this month.'],
            ],
            'social_posts' => [fake()->sentence(10), fake()->sentence(12)],
            'flyer_copy' => [
                'headline' => 'New counters without the runaround',
                'subheadline' => 'One local crew handles the measurement, cut, and install.',
                'bullets' => ['Free in-home measurement', 'Local crew, no subcontractors', 'Most installs done in one day'],
                'call_to_action' => 'Call for a free quote',
            ],
            'image_prompts' => [fake()->sentence(), fake()->sentence()],
            'landing_page_outline' => [
                ['section' => 'Hero', 'content' => 'What we do, who we serve, and one clear quote button.'],
                ['section' => 'How it works', 'content' => 'Measure, cut, install: three steps with real timelines.'],
            ],
            'thirty_day_plan' => [
                ['week' => 1, 'focus' => 'Set up profiles and claim listings', 'actions' => ['Claim the Google Business profile', 'Publish the first three social posts']],
                ['week' => 2, 'focus' => 'Start one paid test', 'actions' => ['Run one small Facebook ad', 'Ask two past customers for reviews']],
            ],
            'provider' => 'fake',
            'model' => 'fake',
            'config_version' => 'v1',
            'raw_response' => null,
            'generated_at' => now(),
        ];
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn (): array => [
            'business_id' => $business->id,
            'user_id' => $business->user_id,
        ]);
    }
}
