<?php

namespace Database\Factories;

use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandKit>
 */
class BrandKitFactory extends Factory
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
            'version' => 1,
            'name_ideas' => [fake()->company(), fake()->company()],
            'tagline_options' => [fake()->sentence(6), fake()->sentence(6)],
            'positioning' => fake()->paragraph(),
            'tone_voice' => ['Plainspoken: says what the business does without hype.'],
            'color_palette' => [
                ['name' => 'Moss', 'hex' => '#2F6B4F', 'usage' => 'Primary buttons and headings', 'role' => 'primary', 'prominence' => 'dominant'],
                ['name' => 'Cream', 'hex' => '#FFF8EE', 'usage' => 'Page background', 'role' => 'background', 'prominence' => 'dominant'],
                ['name' => 'Gold', 'hex' => '#C99A3A', 'usage' => 'Small accents and badges', 'role' => 'accent', 'prominence' => 'supporting'],
            ],
            'font_notes' => ['Pair a bold geometric sans for headings with a readable humanist sans for body copy.'],
            'image_prompts' => [fake()->sentence(), fake()->sentence()],
            'brand_board_prompt' => fake()->paragraph(),
            'social_bios' => [
                ['platform' => 'instagram', 'bio' => fake()->sentence()],
                ['platform' => 'facebook', 'bio' => fake()->sentence()],
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
