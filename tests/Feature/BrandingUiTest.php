<?php

use App\Ai\Text\TextRoleManager;
use App\Enums\TextGenerationRole;
use App\Livewire\Branding\Index;
use App\Models\BrandKit;
use App\Models\Business;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function fakeBrandingUiPayload(): array
{
    return [
        'name_ideas' => ['Hill Country Counters', 'Comal Stoneworks'],
        'tagline_options' => ['Stone counters for real kitchens.', 'Measured, cut, and installed by one crew.'],
        'positioning' => 'A local countertop shop for homeowners who want one crew handling everything.',
        'tone_voice' => ['Plainspoken: describe the work, not the dream.'],
        'color_palette' => [
            ['name' => 'Granite Ink', 'hex' => '#1F2937', 'usage' => 'Headings and text'],
            ['name' => 'Cedar Green', 'hex' => '#2F6B4F', 'usage' => 'Primary buttons'],
        ],
        'font_notes' => ['Pair a bold slab serif with a plain humanist sans.'],
        'image_prompts' => ['Simple monogram logo carved in stone.'],
        'social_bios' => [
            ['platform' => 'instagram', 'bio' => 'Custom countertops in New Braunfels.'],
        ],
    ];
}

test('guests are redirected from branding', function () {
    $this->get(route('branding'))->assertRedirect(route('login'));
});

test('users without a business profile are prompted to complete intake', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('branding'))
        ->assertSuccessful()
        ->assertSee(__('No company profile yet'));
});

test('branding page renders the empty state before any kit exists', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user);

    $this->get(route('branding'))
        ->assertSuccessful()
        ->assertSee(__('No brand kit yet'))
        ->assertSee(__('Generate brand kit'));
});

test('generate action persists a brand kit and shows its sections', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(fakeBrandingUiPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('generate')
        ->assertHasNoErrors()
        ->assertSee('Hill Country Counters')
        ->assertSee('Stone counters for real kitchens.')
        ->assertSee('Granite Ink')
        ->assertSee('#1F2937')
        ->assertSee('Custom countertops in New Braunfels.');

    expect($business->brandKits()->count())->toBe(1);
});

test('a failed generation shows an error state and persists nothing', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => 'not json at all',
    ])->preventStrayPrompts();

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('generate')
        ->assertSet('generationError', __('Brand kit generation did not return usable results. Nothing was saved. Try again in a moment.'))
        ->assertSee(__('Generation failed'));

    expect(BrandKit::query()->count())->toBe(0);
});

test('preferred name tagline and color selections are saved and toggle off', function () {
    $business = Business::factory()->create();
    $kit = BrandKit::factory()->forBusiness($business)->create([
        'name_ideas' => ['Alpha Co', 'Beta Co'],
        'tagline_options' => ['First tagline.', 'Second tagline.'],
        'color_palette' => [
            ['name' => 'Ink', 'hex' => '#101828', 'usage' => 'Text'],
            ['name' => 'Moss', 'hex' => '#2F6B4F', 'usage' => 'Buttons'],
        ],
    ]);

    $component = Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('selectPreference', 'name', 1)
        ->call('selectPreference', 'tagline', 0)
        ->call('selectPreference', 'color', 1);

    expect($kit->refresh()->preferences)->toBe([
        'name' => 'Beta Co',
        'tagline' => 'First tagline.',
        'color' => '#2F6B4F',
    ]);

    $component->assertSee(__('Your picks:'));

    $component->call('selectPreference', 'name', 1);

    expect($kit->refresh()->preferences)->toBe([
        'tagline' => 'First tagline.',
        'color' => '#2F6B4F',
    ]);
});

test('regenerating a section updates it in place and prunes a stale pick', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode([
            'name_ideas' => ['Fresh Name One', 'Fresh Name Two'],
        ]),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    $kit = BrandKit::factory()->forBusiness($business)->create([
        'name_ideas' => ['Old Name'],
        'preferences' => ['name' => 'Old Name', 'tagline' => 'Kept tagline.'],
    ]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('regenerateSection', 'name_ideas')
        ->assertSee('Fresh Name One');

    $kit->refresh();

    expect($kit->name_ideas)->toBe(['Fresh Name One', 'Fresh Name Two'])
        ->and($kit->version)->toBe(1)
        ->and($kit->preferences)->toBe(['tagline' => 'Kept tagline.']);
});

test('regenerating the whole kit creates a new version and keeps the old one', function () {
    TextRoleManager::fake([
        TextGenerationRole::BrandCopy->value => json_encode(fakeBrandingUiPayload()),
    ])->preventStrayPrompts();

    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create(['version' => 1]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('generate')
        ->assertSee(__('Version :version', ['version' => 2]));

    expect($business->brandKits()->count())->toBe(2)
        ->and($business->brandKits()->max('version'))->toBe(2);
});

test('the free flux kit renders the stacked layout without pro tabs', function () {
    config(['flux-ui.kit' => 'flux-free']);

    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Name ideas'))
        ->assertSee(__('Color palette'))
        ->assertSee(__('Brand board prompt'))
        ->assertDontSee(__('Design system'));
});

test('the pro flux kit renders the tabbed layout', function () {
    config(['flux-ui.kit' => 'flux-pro']);

    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Identity'))
        ->assertSee(__('Design system'))
        ->assertSee(__('Assets'))
        ->assertSee(__('Name ideas'));
});

test('the palette separates dominant colors from supporting accents', function () {
    $business = Business::factory()->create();
    $kit = BrandKit::factory()->forBusiness($business)->create([
        'color_palette' => [
            ['name' => 'Ink', 'hex' => '#101828', 'usage' => 'Text', 'role' => 'foreground', 'prominence' => 'dominant'],
            ['name' => 'Cream', 'hex' => '#FFF8EE', 'usage' => 'Background', 'role' => 'background', 'prominence' => 'dominant'],
            ['name' => 'Gold', 'hex' => '#C99A3A', 'usage' => 'Badges', 'role' => 'accent', 'prominence' => 'supporting'],
        ],
    ]);

    $component = Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Supporting accents'))
        ->assertSee('Gold');

    $component->call('selectPreference', 'color', 2);

    expect($kit->refresh()->preferences)->toBe(['color' => '#C99A3A']);
});

test('a palette without prominence metadata renders without a supporting group', function () {
    $business = Business::factory()->create();
    BrandKit::factory()->forBusiness($business)->create([
        'color_palette' => [
            ['name' => 'Ink', 'hex' => '#101828', 'usage' => 'Text'],
            ['name' => 'Moss', 'hex' => '#2F6B4F', 'usage' => 'Buttons'],
        ],
    ]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee('Ink')
        ->assertSee('Moss')
        ->assertDontSee(__('Supporting accents'));
});

test('the brand board prompt shows its content or a regenerate fallback', function () {
    $business = Business::factory()->create();
    $kit = BrandKit::factory()->forBusiness($business)->create([
        'brand_board_prompt' => 'A 3840 x 2160 brand board with homepage and pricing mockups.',
    ]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Brand board prompt'))
        ->assertSee('A 3840 x 2160 brand board with homepage and pricing mockups.');

    $kit->update(['brand_board_prompt' => null]);

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertSee(__('Brand board prompt'))
        ->assertSee(__('Nothing usable came back for this section. Regenerate it to fill it in.'));
});

test('users cannot see another users brand kit', function () {
    $otherBusiness = Business::factory()->create();
    BrandKit::factory()->forBusiness($otherBusiness)->create([
        'name_ideas' => ['Someone Elses Brand'],
    ]);

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->assertDontSee('Someone Elses Brand')
        ->assertSee(__('No brand kit yet'));
});

test('selecting a preference never touches another users kit', function () {
    $otherBusiness = Business::factory()->create();
    $otherKit = BrandKit::factory()->forBusiness($otherBusiness)->create([
        'preferences' => null,
    ]);

    $business = Business::factory()->create();

    Livewire::actingAs($business->user)
        ->test(Index::class)
        ->call('selectPreference', 'name', 0)
        ->assertHasNoErrors();

    expect($otherKit->refresh()->preferences)->toBeNull();
});
