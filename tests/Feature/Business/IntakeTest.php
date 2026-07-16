<?php

use App\Enums\BusinessStage;
use App\Livewire\Business\Intake;
use App\Livewire\Business\ProfileEditor;
use App\Models\Business;
use App\Models\OnboardingDraft;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('business.intake'))->assertRedirect(route('login'));
});

test('authenticated users can view the intake wizard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('business.intake'))
        ->assertOk()
        ->assertSee('Company profile')
        ->assertSee('<progress', false)
        ->assertSee('aria-current="step"', false);
});

test('step one requires a current or desired business name', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('name', '')
        ->set('desired_name', '')
        ->set('industry', 'Landscaping')
        ->call('next')
        ->assertHasErrors(['name', 'desired_name'])
        ->assertSee('role="alert"', false)
        ->assertSee('tabindex="-1"', false)
        ->assertSet('step', 1);
});

test('step one accepts a desired name in place of a business name', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('name', '')
        ->set('desired_name', 'Bluebonnet Lawn Care')
        ->set('dba_status', 'no')
        ->set('industry', 'Landscaping')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

test('step two sends businesses outside Texas to the supported-location explanation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(Intake::class)
        ->set('desired_name', 'Outside Texas Company')
        ->set('industry', 'Consulting')
        ->call('next')
        ->assertSet('step', 2)
        ->set('operates_in_texas', 'no');

    $document = new DOMDocument;
    $previousErrors = libxml_use_internal_errors(true);
    $document->loadHTML($component->html());
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $continue = (new DOMXPath($document))->query('//*[@data-testid="intake-continue"]')?->item(0);

    expect($continue)->toBeInstanceOf(DOMElement::class)
        ->and($continue?->hasAttribute('disabled'))->toBeFalse()
        ->and($continue?->getAttribute('type'))->toBe('submit');

    $component
        ->call('next')
        ->assertRedirect(route('business.not-supported', absolute: false));

    expect(OnboardingDraft::query()->where('account_id', $user->current_account_id)->sole()->payload['operates_in_texas'])
        ->toBe('no');
});

test('each step validates before advancing', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('desired_name', 'Validation Company')
        ->set('industry', 'Consulting')
        ->call('next')
        ->assertSet('step', 2)
        ->call('next')
        ->assertHasErrors(['city', 'county', 'location_type'])
        ->assertSet('step', 2);
});

test('the back button returns to the previous step without validating', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('desired_name', 'Back Button Company')
        ->set('industry', 'Consulting')
        ->call('next')
        ->assertSet('step', 2)
        ->call('back')
        ->assertHasNoErrors()
        ->assertSet('step', 1);
});

test('the current step cannot be changed by the browser', function () {
    $this->actingAs(User::factory()->create());

    expect(fn () => Livewire::test(Intake::class)->set('step', 99))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the existing business profile baseline cannot be changed by the browser', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    $this->actingAs($user);
    $component = Livewire::test(ProfileEditor::class, ['section' => 'location_structure']);

    expect($component->get('baselineEnvelope'))->toBeString()->not->toBeEmpty()
        ->and(fn () => $component->set('baselineEnvelope', 'tampered'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('completing the wizard creates a classified business and opens plan ready', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->set('name', 'Bluebonnet Lawn Care')
        ->set('dba_status', 'yes')
        ->set('industry', 'Landscaping')
        ->set('city', 'Austin')
        ->set('county', 'Travis')
        ->set('location_type', 'mobile_service')
        ->set('legal_structure', 'sole_proprietor')
        ->set('owner_count', 1)
        ->set('employee_count', 0)
        ->set('sells_taxable_goods', 'no')
        ->set('sells_taxable_services', 'unsure')
        ->set('has_sales_tax_permit', 'no')
        ->set('has_ein', 'no')
        ->set('annual_revenue_range', '25k_to_100k')
        ->set('monthly_revenue_range', '1k_to_5k')
        ->set('first_sale_on', '2025-03-01')
        ->set('filing_confidence', 'some_knowledge')
        ->call('next')
        ->call('next')
        ->call('next')
        ->call('next')
        ->assertSet('step', 5)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.plan-ready', absolute: false));

    $business = $user->refresh()->business;

    expect($business)->not->toBeNull()
        ->and($business->name)->toBe('Bluebonnet Lawn Care')
        ->and($business->state)->toBe('TX')
        ->and($business->stage)->toBe(BusinessStage::ExistingDba);
});

test('a business with employees is classified as existing with employees on save', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->set('name', 'Alamo Repairs LLC')
        ->set('dba_status', 'no')
        ->set('industry', 'Repair services')
        ->set('city', 'San Antonio')
        ->set('county', 'Bexar')
        ->set('location_type', 'physical_location')
        ->set('legal_structure', 'llc')
        ->set('owner_count', 2)
        ->set('employee_count', 3)
        ->set('first_employee_on', '2025-01-15')
        ->set('sells_taxable_goods', 'yes')
        ->set('sells_taxable_services', 'yes')
        ->set('has_sales_tax_permit', 'yes')
        ->set('has_ein', 'yes')
        ->set('annual_revenue_range', '250k_to_500k')
        ->set('monthly_revenue_range', '10k_to_25k')
        ->set('filing_confidence', 'has_professional')
        ->call('next')
        ->call('next')
        ->call('next')
        ->call('next')
        ->assertSet('step', 5)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.plan-ready', absolute: false));

    expect($user->refresh()->business->stage)->toBe(BusinessStage::ExistingWithEmployees);
});

test('the profile editor hydrates from an existing business and updates it without duplicating', function () {
    $user = User::factory()->create();
    $business = Business::factory()->operatingDba()->for($user)->create(['city' => 'Austin']);
    $this->actingAs($user);

    Livewire::test(ProfileEditor::class, ['section' => 'location_structure'])
        ->assertSet('values.city', 'Austin')
        ->set('values.city', 'Round Rock')
        ->set('values.county', 'Williamson')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('savedStatus', 'Saved as profile revision 2.');

    expect(Business::count())->toBe(1)
        ->and($business->refresh()->city)->toBe('Round Rock');
});

test('an existing business is redirected from intake to the profile hub without creating a draft', function () {
    $user = User::factory()->create();
    $business = Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('business.intake'))->assertRedirect(route('business.edit'));

    expect(OnboardingDraft::query()->where('account_id', $user->current_account_id)->doesntExist())->toBeTrue()
        ->and($business->refresh()->profile_revision)->toBe(0);
});

test('a stale existing business section editor cannot overwrite a newer section save', function () {
    $user = User::factory()->create();
    $business = Business::factory()->operatingDba()->for($user)->create([
        'city' => 'Austin',
        'county' => 'Travis',
    ]);
    $this->actingAs($user);
    $staleEditor = Livewire::test(ProfileEditor::class, ['section' => 'location_structure']);
    $newerEditor = Livewire::test(ProfileEditor::class, ['section' => 'location_structure']);

    $newerEditor
        ->set('values.city', 'Dallas')
        ->set('values.county', 'Dallas')
        ->call('save')
        ->assertHasNoErrors();

    $staleEditor
        ->set('values.city', 'Houston')
        ->set('values.county', 'Harris')
        ->call('save')
        ->assertHasErrors(['profile'])
        ->assertSee('Some fields changed elsewhere.')
        ->assertSee('role="alert"', false)
        ->assertNotDispatched('toast-show')
        ->assertNoRedirect();

    expect($business->refresh()->city)->toBe('Dallas')
        ->and($business->county)->toBe('Dallas');
});
