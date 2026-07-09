<?php

use App\Ai\Text\TextRoleManager;
use App\Enums\TextGenerationRole;
use App\Livewire\Advertising\Index;
use App\Models\AdvertisingKit;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function fakeAdvertisingUiPayload(): array
{
    return [
        'ad_angles' => ['Lead with the one-crew promise.'],
        'facebook_instagram_copy' => [
            ['headline' => 'One crew, start to finish', 'body' => 'We measure, cut, and install ourselves.', 'cta' => 'Get a quote'],
        ],
        'google_ads' => [
            ['headline' => 'Local Countertop Install', 'description' => 'Measured, cut, and installed by one crew.'],
        ],
        'social_posts' => ['A quartz island we installed in Gruene last week.'],
        'flyer_copy' => [
            'headline' => 'New counters without the runaround',
            'subheadline' => 'One local crew handles everything.',
            'bullets' => ['Free in-home measurement'],
            'call_to_action' => 'Call for a free quote',
        ],
        'image_prompts' => ['Close-up photo of a granite countertop edge.'],
        'landing_page_outline' => [
            ['section' => 'Hero', 'content' => 'What we do and one quote button.'],
        ],
        'thirty_day_plan' => [
            ['week' => 1, 'focus' => 'Set up profiles', 'actions' => ['Claim the Google Business profile']],
        ],
    ];
}

test('guests are redirected from advertising', function () {
    $this->get(route('advertising'))->assertRedirect(route('login'));
});

test('users without a business profile are prompted to complete intake', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('advertising'))
        ->assertSuccessful()
        ->assertSee(__('No company profile yet'));
});

test('advertising page renders the empty state with a brand kit hint before any kit exists', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user);

    $this->get(route('advertising'))
        ->assertSuccessful()
        ->assertSee(__('No advertising kit yet'))
        ->assertSee(__('Generate advertising kit'))
        ->assertSee(__('Generate a brand kit first'));
});

test('the empty state names the brand kit version when one exists', function () {
    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create(['version' => 3]);

    $this->actingAs($business->user);

    $this->get(route('advertising'))
        ->assertSuccessful()
        ->assertSee(__('Your brand kit (version :version) will keep names, tone, and colors consistent.', ['version' => 3]));
});

test('generate action persists an advertising kit and shows its sections', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(fakeAdvertisingUiPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('Lead with the one-crew promise.')
        ->assertSee('One crew, start to finish')
        ->assertSee('Local Countertop Install')
        ->assertSee('New counters without the runaround')
        ->assertSee(__('Week :week: :focus', ['week' => 1, 'focus' => 'Set up profiles']))
        ->assertSee(__('Generated without a brand kit.'));

    expect($business->advertisingKits()->count())->toBe(1);
});

test('a failed generation shows an error state and persists nothing', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => 'not json at all',
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('generate')
        ->assertSet('generationError', __('Advertising generation did not return usable results. Nothing was saved. Try again in a moment.'))
        ->assertSee(__('Generation failed'));

    expect(AdvertisingKit::query()->count())->toBe(0);
});

test('the kit view names the brand kit version that grounded it', function () {
    $business = Business::factory()->create();
    $brandKit = BrandKit::factory()->forBusiness($business)->create(['version' => 2]);
    AdvertisingKit::factory()->forBusiness($business)->create(['brand_kit_id' => $brandKit->id]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Grounded in brand kit version :version.', ['version' => 2]));
});

test('generating a new version keeps the old one and switches to it', function () {
    TextRoleManager::fake([
        TextGenerationRole::AdCopy->value => json_encode(fakeAdvertisingUiPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    AdvertisingKit::factory()->forBusiness($business)->create(['version' => 1]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('generate')
        ->assertSee(__('Version :version', ['version' => 2]));

    expect($business->advertisingKits()->count())->toBe(2)
        ->and($business->advertisingKits()->max('version'))->toBe(2);
});

test('empty sections render a fallback message', function () {
    $business = Business::factory()->create();
    AdvertisingKit::factory()->forBusiness($business)->create([
        'ad_angles' => [],
        'flyer_copy' => null,
    ]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Ad angles'))
        ->assertSee(__('Flyer copy'))
        ->assertSee(__('Nothing usable came back for this section. Generate a new version to fill it in.'));
});

test('users cannot see another users advertising kit', function () {
    $otherBusiness = Business::factory()->create();
    AdvertisingKit::factory()->forBusiness($otherBusiness)->create([
        'ad_angles' => ['Someone elses ad angle'],
    ]);

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertDontSee('Someone elses ad angle')
        ->assertSee(__('No advertising kit yet'));
});
