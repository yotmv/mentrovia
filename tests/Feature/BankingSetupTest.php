<?php

use App\Enums\BusinessProfileVersionSource;
use App\Enums\LegalStructure;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Models\BusinessProfile;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('banking-setup'))->assertRedirect(route('login'));
});

test('users without a business are redirected to onboarding', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('banking-setup'))->assertRedirect(route('onboarding.welcome'));
});

test('no ein and no business bank path gives next banking actions', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create([
        'has_ein' => YesNoUnsure::No,
        'has_business_bank' => false,
        'sells_taxable_goods' => YesNoUnsure::No,
        'sells_taxable_services' => YesNoUnsure::No,
    ]);
    $this->actingAs($user);

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertSee('Open a dedicated business checking account')
        ->assertSee('Get or confirm an EIN before the bank visit')
        ->assertSee('Your profile does not show an EIN')
        ->assertSee('Mark done')
        ->assertSee('not legal, tax, payroll, or accounting advice');
});

test('dba path includes assumed name documents', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->operatingDba()->create([
        'has_ein' => YesNoUnsure::Yes,
    ]);
    $this->actingAs($user);

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertSee('Assumed name / DBA certificate')
        ->assertSee('DBA path');
});

test('llc path includes formation and operating documents', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->formalEntity()->create();
    $this->actingAs($user);

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertSee('Formation documents')
        ->assertSee('Operating agreement');
});

test('sales tax reserve is visible only when taxable sales may apply', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create([
        'sells_taxable_goods' => YesNoUnsure::Yes,
        'sells_taxable_services' => YesNoUnsure::No,
        'has_sales_tax_permit' => YesNoUnsure::Yes,
    ]);
    $this->actingAs($user);

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertSee('Add a sales tax reserve')
        ->assertSee('Sales tax permit status');

    $userWithoutTaxableSales = User::factory()->create();
    Business::factory()->for($userWithoutTaxableSales)->create([
        'sells_taxable_goods' => YesNoUnsure::No,
        'sells_taxable_services' => YesNoUnsure::No,
    ]);
    $this->actingAs($userWithoutTaxableSales);

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertDontSee('Add a sales tax reserve');
});

test('payroll reserve is visible when employees or payroll apply', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->withEmployees()->create([
        'has_payroll' => false,
    ]);
    $this->actingAs($user);

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertSee('Add a payroll reserve')
        ->assertSee('Payroll provider debit details');
});

test('users can mark checklist items done', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create();
    $this->actingAs($user);

    $this->patch(route('banking-setup.items.update', ['key' => 'tax-reserve']), [
        'completed' => true,
    ])->assertRedirect(route('banking-setup'));

    $profileAnswer = BusinessProfile::query()
        ->whereBelongsTo($business)
        ->where('question_key', 'banking_setup.tax-reserve')
        ->firstOrFail();

    $this->assertModelExists($profileAnswer);

    $versions = $business->profileVersions()->orderBy('revision')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions->last()->source)->toBe(BusinessProfileVersionSource::Workflow)
        ->and($versions->last()->changed_field_keys)->toBe(['profile_answers.banking_setup.tax-reserve'])
        ->and(collect($versions->last()->snapshot['profile_answers'])->firstWhere('question_key', 'banking_setup.tax-reserve')['answer_value'])->toBe('done');

    $this->patch(route('banking-setup.items.update', ['key' => 'tax-reserve']), [
        'completed' => true,
    ])->assertRedirect(route('banking-setup'));

    expect($business->profileVersions()->count())->toBe(2);

    $this->get(route('business.profile.history'))
        ->assertOk()
        ->assertSee('Tax Reserve')
        ->assertSee('done · User Confirmed');

    $this->get(route('banking-setup'))
        ->assertOk()
        ->assertSee('1 of')
        ->assertSee('Undo');
});

test('marking a legacy checklist answer done repairs its value and provenance in place', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create();
    $answer = BusinessProfile::factory()->for($business)->create([
        'question_key' => 'banking_setup.tax-reserve',
        'answer_value' => 'legacy_partial',
        'confidence' => null,
    ]);
    $this->actingAs($user);

    $this->patch(route('banking-setup.items.update', ['key' => 'tax-reserve']), [
        'completed' => true,
    ])->assertRedirect(route('banking-setup'));

    expect($answer->refresh()->answer_value)->toBe('done')
        ->and($answer->confidence)->toBe('user_confirmed')
        ->and($business->profileAnswers()->where('question_key', 'banking_setup.tax-reserve')->count())->toBe(1)
        ->and($business->profileVersions()->count())->toBe(2)
        ->and($business->profileVersions()->latest('revision')->firstOrFail()->changed_field_keys)
        ->toBe(['profile_answers.banking_setup.tax-reserve']);
});

test('marking dedicated checking done updates the core bank profile flag', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create(['has_business_bank' => false]);
    $this->actingAs($user);

    $this->patch(route('banking-setup.items.update', ['key' => 'dedicated-checking']), [
        'completed' => true,
    ])->assertRedirect(route('banking-setup'));

    expect($business->refresh()->has_business_bank)->toBeTrue();
});

test('dashboard risk flags and roadmap link to the banking guide', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create([
        'first_sale_on' => now()->subMonth(),
        'legal_structure' => LegalStructure::SoleProprietor,
        'has_business_bank' => false,
        'has_ein' => YesNoUnsure::No,
    ]);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Open the banking guide')
        ->assertSee(route('guides.show', 'banking'), escape: false);

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertSee('Open the banking guide')
        ->assertSee(route('guides.show', 'banking'), escape: false);
});
