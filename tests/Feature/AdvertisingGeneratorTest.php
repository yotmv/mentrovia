<?php

use App\Ai\Text\TextGenerationRequest;
use App\Ai\Text\TextRoleManager;
use App\Enums\TextGenerationRole;
use App\Models\AdvertisingKit;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use App\Services\Advertising\AdvertisingKitGenerationException;
use App\Services\Advertising\AdvertisingKitGenerator;

/**
 * @return array<string, mixed>
 */
function fakeAdvertisingPayload(): array
{
    return [
        'ad_angles' => [
            'Lead with the one-crew promise: the people who measure are the people who install.',
            'Lead with turnaround time for homeowners mid-renovation.',
        ],
        'facebook_instagram_copy' => [
            ['headline' => 'One crew, start to finish', 'body' => 'We measure, cut, and install your counters ourselves.', 'cta' => 'Get a quote'],
            ['headline' => 'Counters without the runaround', 'body' => 'One local shop handles the whole job.', 'cta' => 'Book a measurement'],
        ],
        'google_ads' => [
            ['headline' => 'Local Countertop Install', 'description' => 'Measured, cut, and installed by one local crew. Free in-home quotes.'],
        ],
        'social_posts' => [
            'Template Tuesday: here is how we measure a kitchen in under an hour.',
            'A quartz island we installed in Gruene last week, start to finish in one day.',
        ],
        'flyer_copy' => [
            'headline' => 'New counters without the runaround',
            'subheadline' => 'One local crew handles the measurement, cut, and install.',
            'bullets' => ['Free in-home measurement', 'Local crew, no subcontractors'],
            'call_to_action' => 'Call for a free quote',
        ],
        'image_prompts' => [
            'Close-up photo of a granite countertop edge in warm morning light.',
            'Wide photo of an installer leveling a quartz island in a bright kitchen.',
        ],
        'landing_page_outline' => [
            ['section' => 'Hero', 'content' => 'What we do, who we serve, and one quote button.'],
            ['section' => 'How it works', 'content' => 'Measure, cut, install with real timelines.'],
        ],
        'thirty_day_plan' => [
            ['week' => 1, 'focus' => 'Set up profiles', 'actions' => ['Claim the Google Business profile', 'Publish three posts']],
            ['week' => 2, 'focus' => 'Start one paid test', 'actions' => ['Run one small Facebook ad']],
        ],
    ];
}

test('advertising generation with a fake provider persists structured output', function () {
    $fake = TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(fakeAdvertisingPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    $kit = app(AdvertisingKitGenerator::class)->generate($business->user, $business);

    expect($kit)->toBeInstanceOf(AdvertisingKit::class)
        ->and($kit->exists)->toBeTrue()
        ->and($kit->version)->toBe(1)
        ->and($kit->brand_kit_id)->toBeNull()
        ->and($kit->ad_angles)->toHaveCount(2)
        ->and($kit->facebook_instagram_copy)->toBe([
            ['headline' => 'One crew, start to finish', 'body' => 'We measure, cut, and install your counters ourselves.', 'cta' => 'Get a quote'],
            ['headline' => 'Counters without the runaround', 'body' => 'One local shop handles the whole job.', 'cta' => 'Book a measurement'],
        ])
        ->and($kit->google_ads)->toBe([
            ['headline' => 'Local Countertop Install', 'description' => 'Measured, cut, and installed by one local crew. Free in-home quotes.'],
        ])
        ->and($kit->social_posts)->toHaveCount(2)
        ->and($kit->flyer_copy['headline'])->toBe('New counters without the runaround')
        ->and($kit->flyer_copy['bullets'])->toHaveCount(2)
        ->and($kit->image_prompts)->toHaveCount(2)
        ->and($kit->landing_page_outline)->toBe([
            ['section' => 'Hero', 'content' => 'What we do, who we serve, and one quote button.'],
            ['section' => 'How it works', 'content' => 'Measure, cut, install with real timelines.'],
        ])
        ->and($kit->thirty_day_plan)->toHaveCount(2)
        ->and($kit->thirty_day_plan[0]['week'])->toBe(1)
        ->and($kit->provider)->toBe('fake')
        ->and($kit->generated_at)->not->toBeNull();

    $fake->assertGenerated(function (TextGenerationRequest $request) use ($business): bool {
        return $request->role === TextGenerationRole::AdCopy
            && $request->context['business']['industry'] === $business->industry
            && ! array_key_exists('brand_kit', $request->context)
            && str_contains($request->prompt, 'No brand kit exists yet');
    });
});

test('advertising generation includes brand kit context when one exists', function () {
    $fake = TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(fakeAdvertisingPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create(['version' => 1]);
    $latestBrandKit = BrandKit::factory()->forBusiness($business)->create([
        'version' => 2,
        'positioning' => 'A local countertop shop for homeowners.',
        'preferences' => ['name' => 'Comal Stoneworks'],
    ]);

    $kit = app(AdvertisingKitGenerator::class)->generate($business->user, $business);

    expect($kit->brand_kit_id)->toBe($latestBrandKit->id)
        ->and($kit->brandKit->is($latestBrandKit))->toBeTrue();

    $fake->assertGenerated(function (TextGenerationRequest $request): bool {
        return $request->role === TextGenerationRole::AdCopy
            && $request->context['brand_kit']['version'] === 2
            && $request->context['brand_kit']['positioning'] === 'A local countertop shop for homeowners.'
            && $request->context['brand_kit']['preferences'] === ['name' => 'Comal Stoneworks']
            && str_contains($request->prompt, 'A brand kit is provided');
    });
});

test('advertising kits are scoped to the owning user and business', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(fakeAdvertisingPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    $otherUser = User::factory()->create();

    $kit = app(AdvertisingKitGenerator::class)->generate($business->user, $business);

    expect($business->user->advertisingKits()->pluck('id')->all())->toBe([$kit->id])
        ->and($business->advertisingKits()->pluck('id')->all())->toBe([$kit->id])
        ->and($otherUser->advertisingKits()->count())->toBe(0)
        ->and($kit->user->is($business->user))->toBeTrue()
        ->and($kit->business->is($business))->toBeTrue();
});

test('regeneration creates a new advertising kit version and keeps earlier versions', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(fakeAdvertisingPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    $generator = app(AdvertisingKitGenerator::class);

    $first = $generator->generate($business->user, $business);
    $second = $generator->generate($business->user, $business);

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($second->id)->not->toBe($first->id)
        ->and($business->advertisingKits()->count())->toBe(2)
        ->and(AdvertisingKit::query()->whereKey($first->id)->exists())->toBeTrue();
});

test('an unstructured model response fails without persisting an advertising kit', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => 'Here are some great ad ideas: buy now!',
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    expect(fn () => app(AdvertisingKitGenerator::class)->generate($business->user, $business))
        ->toThrow(AdvertisingKitGenerationException::class);

    expect(AdvertisingKit::query()->count())->toBe(0);
});

test('a structured response with no usable sections fails without persisting a kit', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(['unexpected' => 'shape', 'ad_angles' => [42, null]]),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    expect(fn () => app(AdvertisingKitGenerator::class)->generate($business->user, $business))
        ->toThrow(AdvertisingKitGenerationException::class);

    expect(AdvertisingKit::query()->count())->toBe(0);
});

test('malformed sections are coerced to safe structured values', function () {
    $payload = fakeAdvertisingPayload();
    $payload['ad_angles'] = ['Good angle', 42, '', null];
    $payload['facebook_instagram_copy'] = [
        ['headline' => 'Valid ad', 'body' => 'Valid body.'],
        ['headline' => 'Missing body'],
        'just a string',
    ];
    $payload['google_ads'] = [
        ['headline' => 'Valid', 'description' => 'Valid description.'],
        ['headline' => 'No description'],
    ];
    $payload['flyer_copy'] = ['subheadline' => 'No headline so this is unusable.'];
    $payload['landing_page_outline'] = [
        ['section' => 'Hero'],
        ['content' => 'No section name.'],
    ];
    $payload['thirty_day_plan'] = [
        ['week' => 'one', 'focus' => 'Fuzzy week number', 'actions' => ['Do a thing', 7]],
        ['actions' => ['No focus so dropped']],
    ];

    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode($payload),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    $kit = app(AdvertisingKitGenerator::class)->generate($business->user, $business);

    expect($kit->ad_angles)->toBe(['Good angle'])
        ->and($kit->facebook_instagram_copy)->toBe([
            ['headline' => 'Valid ad', 'body' => 'Valid body.', 'cta' => ''],
        ])
        ->and($kit->google_ads)->toBe([
            ['headline' => 'Valid', 'description' => 'Valid description.'],
        ])
        ->and($kit->flyer_copy)->toBeNull()
        ->and($kit->landing_page_outline)->toBe([
            ['section' => 'Hero', 'content' => ''],
        ])
        ->and($kit->thirty_day_plan)->toBe([
            ['week' => 1, 'focus' => 'Fuzzy week number', 'actions' => ['Do a thing']],
        ]);
});

test('deleting a brand kit keeps the advertising kit and nulls the reference', function () {
    $business = Business::factory()->create();
    $brandKit = BrandKit::factory()->forBusiness($business)->create();
    $kit = AdvertisingKit::factory()->forBusiness($business)->create(['brand_kit_id' => $brandKit->id]);

    $brandKit->delete();

    expect($kit->refresh()->brand_kit_id)->toBeNull()
        ->and(AdvertisingKit::query()->whereKey($kit->id)->exists())->toBeTrue();
});
