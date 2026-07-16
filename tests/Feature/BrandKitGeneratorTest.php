<?php

use App\Ai\Text\TextGenerationRequest;
use App\Ai\Text\TextRoleManager;
use App\Enums\TextGenerationRole;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use App\Services\Branding\BrandKitGenerationException;
use App\Services\Branding\BrandKitGenerator;

/**
 * @return array<string, mixed>
 */
function fakeBrandKitPayload(): array
{
    return [
        'name_ideas' => ['Hill Country Counters', 'Comal Stoneworks', 'Guadalupe Surfaces'],
        'tagline_options' => ['Countertops cut and installed by the people who measure them.', 'Stone counters for real kitchens.'],
        'positioning' => 'A local countertop shop for homeowners who want the measurement, cut, and install handled by one crew.',
        'tone_voice' => ['Plainspoken: describe the work, not the dream.', 'Local: name the towns and neighborhoods served.'],
        'color_palette' => [
            ['name' => 'Granite Ink', 'hex' => '#1F2937', 'usage' => 'Headings and text', 'role' => 'foreground', 'prominence' => 'dominant'],
            ['name' => 'Limestone', 'hex' => '#F5F1E8', 'usage' => 'Background', 'role' => 'background', 'prominence' => 'dominant'],
            ['name' => 'Cedar Green', 'hex' => '#2f6b4f', 'usage' => 'Primary buttons', 'role' => 'primary', 'prominence' => 'supporting'],
        ],
        'font_notes' => ['Pair a bold slab serif for headings with a plain humanist sans for body copy.'],
        'image_prompts' => ['Close-up photo of a granite countertop edge in warm morning light.', 'Simple monogram logo of interlocking C letters carved in stone.'],
        'brand_board_prompt' => 'One 3840 x 2160 brand board: homepage mockup left, services page mockup middle, typography and color rail right.',
        'social_bios' => [
            ['platform' => 'instagram', 'bio' => 'Custom countertops in New Braunfels. Measured, cut, and installed by our own crew.'],
            ['platform' => 'facebook', 'bio' => 'Family countertop shop serving Comal County.'],
        ],
    ];
}

test('brand kit generation with a fake provider persists structured output', function () {
    $fake = TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(fakeBrandKitPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);

    expect($kit)->toBeInstanceOf(BrandKit::class)
        ->and($kit->exists)->toBeTrue()
        ->and($kit->version)->toBe(1)
        ->and($kit->name_ideas)->toBe(['Hill Country Counters', 'Comal Stoneworks', 'Guadalupe Surfaces'])
        ->and($kit->tagline_options)->toHaveCount(2)
        ->and($kit->positioning)->toContain('countertop shop')
        ->and($kit->tone_voice)->toHaveCount(2)
        ->and($kit->color_palette)->toBe([
            ['name' => 'Granite Ink', 'hex' => '#1F2937', 'usage' => 'Headings and text', 'role' => 'foreground', 'prominence' => 'dominant'],
            ['name' => 'Limestone', 'hex' => '#F5F1E8', 'usage' => 'Background', 'role' => 'background', 'prominence' => 'dominant'],
            ['name' => 'Cedar Green', 'hex' => '#2F6B4F', 'usage' => 'Primary buttons', 'role' => 'primary', 'prominence' => 'supporting'],
        ])
        ->and($kit->font_notes)->toHaveCount(1)
        ->and($kit->image_prompts)->toHaveCount(2)
        ->and($kit->brand_board_prompt)->toContain('3840 x 2160')
        ->and($kit->social_bios)->toBe([
            ['platform' => 'instagram', 'bio' => 'Custom countertops in New Braunfels. Measured, cut, and installed by our own crew.'],
            ['platform' => 'facebook', 'bio' => 'Family countertop shop serving Comal County.'],
        ])
        ->and($kit->provider)->toBe('fake')
        ->and($kit->generated_at)->not->toBeNull();

    $fake->assertGenerated(function (TextGenerationRequest $request) use ($business): bool {
        return $request->role === TextGenerationRole::BrandCopy
            && $request->context['business']['industry'] === $business->industry
            && str_contains($request->context['style_reference']['reference'], 'docs/sample-static-site');
    });
});

test('brand kit generation parses markdown fenced json responses', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => "```json\n".json_encode(fakeBrandKitPayload())."\n```",
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);

    expect($kit->name_ideas)->toHaveCount(3)
        ->and($kit->raw_response)->toHaveKey('name_ideas');
});

test('brand kit generation extracts JSON after explanatory model text', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => "Here is the requested brand kit:\n```json\n".json_encode(fakeBrandKitPayload())."\n```\nUse these ideas as a starting point.",
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);

    expect($kit->name_ideas)->toHaveCount(3)
        ->and($kit->raw_response)->toHaveKey('color_palette');
});

test('brand kit generation rejects JSON arrays and leaves existing versions untouched', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => '```json\n[]\n```',
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create(['version' => 1]);

    expect(fn () => app(BrandKitGenerator::class)->generate($business->user, $business))
        ->toThrow(BrandKitGenerationException::class);

    expect($business->brandKits()->count())->toBe(1)
        ->and($business->brandKits()->value('version'))->toBe(1);
});

test('brand kits are scoped to the owning user and business', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(fakeBrandKitPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    $otherUser = User::factory()->create();

    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);

    expect($business->user->brandKits()->pluck('id')->all())->toBe([$kit->id])
        ->and($business->brandKits()->pluck('id')->all())->toBe([$kit->id])
        ->and($otherUser->brandKits()->count())->toBe(0)
        ->and($kit->user->is($business->user))->toBeTrue()
        ->and($kit->business->is($business))->toBeTrue();
});

test('regeneration creates a new brand kit version and keeps earlier versions', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(fakeBrandKitPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    $generator = app(BrandKitGenerator::class);

    $first = $generator->generate($business->user, $business);
    $second = $generator->generate($business->user, $business);

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and($business->brandKits()->count())->toBe(2)
        ->and(BrandKit::query()->whereKey($first->id)->exists())->toBeTrue();
});

test('an unstructured model response fails without persisting a brand kit', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => 'Here are some brand ideas: call it Sparkle Co!',
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    expect(fn () => app(BrandKitGenerator::class)->generate($business->user, $business))
        ->toThrow(BrandKitGenerationException::class);

    expect(BrandKit::query()->count())->toBe(0);
});

test('the brand board prompt section can be regenerated in place', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode([
            'brand_board_prompt' => 'Fresh 3840 x 2160 board prompt with homepage and pricing mockups plus a typography and color rail.',
        ]),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    $kit = BrandKit::factory()->forBusiness($business)->create(['brand_board_prompt' => 'Old board prompt.']);

    app(BrandKitGenerator::class)->regenerateSection($kit, 'brand_board_prompt', $kit->user);

    expect($kit->refresh()->brand_board_prompt)->toContain('Fresh 3840 x 2160')
        ->and($kit->version)->toBe(1);
});

test('malformed sections are coerced to safe structured values', function () {
    $payload = fakeBrandKitPayload();
    $payload['name_ideas'] = ['Good Name', 42, '', null];
    $payload['color_palette'] = [
        ['name' => 'Valid', 'hex' => '2F6B4F', 'usage' => 'Primary', 'prominence' => 'invalid-value'],
        ['name' => 'Broken', 'hex' => 'not-a-color', 'usage' => 'Nothing'],
        'just a string',
    ];
    $payload['social_bios'] = ['instagram' => 'Bio via platform map.'];
    $payload['positioning'] = ['unexpected' => 'array'];

    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode($payload),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    $kit = app(BrandKitGenerator::class)->generate($business->user, $business);

    expect($kit->name_ideas)->toBe(['Good Name'])
        ->and($kit->color_palette)->toBe([
            ['name' => 'Valid', 'hex' => '#2F6B4F', 'usage' => 'Primary', 'role' => '', 'prominence' => 'supporting'],
        ])
        ->and($kit->social_bios)->toBe([
            ['platform' => 'instagram', 'bio' => 'Bio via platform map.'],
        ])
        ->and($kit->positioning)->toBeNull();
});
