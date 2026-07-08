<?php

use App\Enums\BusinessStage;
use App\Livewire\Business\Intake;
use App\Models\Business;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('business.intake'))->assertRedirect(route('login'));
});

test('authenticated users can view the intake wizard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('business.intake'))->assertOk()->assertSee('Company profile');
});

test('step one requires a current or desired business name', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('name', '')
        ->set('desired_name', '')
        ->set('industry', 'Landscaping')
        ->call('next')
        ->assertHasErrors(['name', 'desired_name'])
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

test('step two blocks businesses outside texas', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('step', 2)
        ->set('operates_in_texas', 'no')
        ->set('city', 'Tulsa')
        ->set('county', 'Tulsa')
        ->set('location_type', 'physical_location')
        ->call('next')
        ->assertHasErrors(['operates_in_texas'])
        ->assertSet('step', 2);
});

test('each step validates before advancing', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('step', 2)
        ->call('next')
        ->assertHasErrors(['city', 'county', 'location_type'])
        ->assertSet('step', 2);
});

test('the back button returns to the previous step without validating', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('step', 3)
        ->call('back')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

test('completing the wizard creates a classified business and redirects to the dashboard', function () {
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
        ->set('step', 5)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

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
        ->set('step', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->business->stage)->toBe(BusinessStage::ExistingWithEmployees);
});

test('the wizard hydrates from an existing business and updates it without duplicating', function () {
    $user = User::factory()->create();
    $business = Business::factory()->operatingDba()->for($user)->create(['city' => 'Austin']);
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->assertSet('city', 'Austin')
        ->set('city', 'Round Rock')
        ->set('county', 'Williamson')
        ->set('step', 5)
        ->set('filing_confidence', 'mostly_set_up')
        ->call('save')
        ->assertHasNoErrors();

    expect(Business::count())->toBe(1)
        ->and($business->refresh()->city)->toBe('Round Rock');
});
