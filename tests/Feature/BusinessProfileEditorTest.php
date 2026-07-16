<?php

use App\Actions\Business\UpdateBusinessProfileSection;
use App\Enums\BusinessProfileSection;
use App\Exceptions\BusinessProfileConflictException;
use App\Livewire\Business\ProfileEditor;
use App\Models\Business;
use App\Models\User;
use App\Services\RoadmapPlanSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function locationProfileValues(Business $business, array $overrides = []): array
{
    return [
        'city' => $business->city,
        'county' => $business->county,
        'state' => $business->state,
        'location_type' => $business->location_type->value,
        'address' => $business->address,
        'legal_structure' => $business->legal_structure->value,
        'tax_classification' => $business->tax_classification,
        ...$overrides,
    ];
}

function peopleProfileValues(Business $business, array $overrides = []): array
{
    return [
        'owner_count' => $business->owner_count,
        'employee_count' => $business->employee_count,
        'uses_contractors' => $business->uses_contractors,
        'first_employee_on' => $business->first_employee_on?->format('Y-m-d'),
        'sells_taxable_goods' => $business->sells_taxable_goods->value,
        'sells_taxable_services' => $business->sells_taxable_services->value,
        'has_sales_tax_permit' => $business->has_sales_tax_permit->value,
        'has_ein' => $business->has_ein->value,
        ...$overrides,
    ];
}

test('nonoverlapping edits from the same section merge without overwriting stale untouched values', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin', 'county' => 'Travis']);
    $action = app(UpdateBusinessProfileSection::class);
    $firstBaseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);
    $secondBaseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);

    $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['city' => 'Dallas']),
        $firstBaseline,
    );
    $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['county' => 'Williamson']),
        $secondBaseline,
    );

    expect($business->refresh()->city)->toBe('Dallas')
        ->and($business->county)->toBe('Williamson')
        ->and($business->profileVersions()->count())->toBe(3);
});

test('overlapping edits fail with current and submitted values and never overwrite', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin']);
    $action = app(UpdateBusinessProfileSection::class);
    $firstBaseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);
    $staleBaseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);
    $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['city' => 'Dallas']),
        $firstBaseline,
    );

    try {
        $action->handle(
            $owner->currentAccount,
            $business,
            $owner,
            BusinessProfileSection::LocationStructure,
            locationProfileValues($business, ['city' => 'Houston']),
            $staleBaseline,
        );
        $this->fail('Expected a profile conflict.');
    } catch (BusinessProfileConflictException $exception) {
        expect($exception->conflicts)->toBe([
            'city' => ['current' => 'Dallas', 'yours' => 'Houston'],
        ])->and($exception->yourPatch)->toBe(['city' => 'Houston']);
    }

    expect($business->refresh()->city)->toBe('Dallas')
        ->and($business->profileVersions()->count())->toBe(2);
});

test('employee count zero atomically clears first employee and payroll with one version', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->withEmployees(3)->for($owner)->create(['has_payroll' => true]);
    $action = app(UpdateBusinessProfileSection::class);
    $baseline = $action->baselineEnvelope($business, BusinessProfileSection::PeopleObligations);

    $result = $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::PeopleObligations,
        peopleProfileValues($business, ['employee_count' => 0]),
        $baseline,
    );

    expect($result['changed_fields'])->toEqualCanonicalizing(['employee_count', 'first_employee_on', 'has_payroll', 'stage'])
        ->and($business->refresh()->employee_count)->toBe(0)
        ->and($business->first_employee_on)->toBeNull()
        ->and($business->has_payroll)->toBeFalse()
        ->and($business->profileVersions()->count())->toBe(2)
        ->and($business->profileVersions()->latest('revision')->firstOrFail()->sections)
        ->toEqualCanonicalizing(['company_basics', 'operations_readiness', 'people_obligations']);
});

test('section payloads reject forged fields before any profile mutation', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin']);
    $action = app(UpdateBusinessProfileSection::class);
    $baseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);

    expect(fn () => $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['account_id' => $owner->current_account_id]),
        $baseline,
    ))->toThrow(ValidationException::class)
        ->and($business->refresh()->city)->toBe('Austin')
        ->and($business->profileVersions()->count())->toBe(0);
});

test('downstream synchronization failure rolls back the profile and its versions', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin']);
    $synchronizer = $this->mock(RoadmapPlanSynchronizer::class);
    $synchronizer->shouldReceive('syncAfterAuthorizedProfileMutation')->once()->andThrow(new RuntimeException('Synchronization failed.'));
    $action = app(UpdateBusinessProfileSection::class);
    $baseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);

    expect(fn () => $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['city' => 'Dallas']),
        $baseline,
    ))->toThrow(RuntimeException::class, 'Synchronization failed.');

    expect($business->refresh()->city)->toBe('Austin')
        ->and($business->profile_revision)->toBe(0)
        ->and($business->profileVersions()->count())->toBe(0);
});

test('zero employee invariants reject payroll and first employee values even when current values are already clear', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create([
        'employee_count' => 0,
        'first_employee_on' => null,
        'has_payroll' => false,
    ]);
    $action = app(UpdateBusinessProfileSection::class);
    $operationsBaseline = $action->baselineEnvelope($business, BusinessProfileSection::OperationsReadiness);
    $operations = [
        'annual_revenue_range' => $business->annual_revenue_range->value,
        'monthly_revenue_range' => $business->monthly_revenue_range->value,
        'first_sale_on' => $business->first_sale_on?->format('Y-m-d'),
        'has_business_bank' => $business->has_business_bank,
        'has_bookkeeping' => $business->has_bookkeeping,
        'has_payroll' => true,
        'filing_confidence' => $business->filing_confidence->value,
    ];
    $result = $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::OperationsReadiness,
        $operations,
        $operationsBaseline,
    );
    $peopleBaseline = $action->baselineEnvelope($business->fresh(), BusinessProfileSection::PeopleObligations);
    $resultDate = $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::PeopleObligations,
        peopleProfileValues($business->fresh(), ['first_employee_on' => '2026-01-01']),
        $peopleBaseline,
    );

    expect($result['changed'])->toBeFalse()
        ->and($resultDate['changed'])->toBeFalse()
        ->and($business->refresh()->has_payroll)->toBeFalse()
        ->and($business->first_employee_on)->toBeNull()
        ->and($business->profileVersions()->count())->toBe(1);
});

test('outsiders cannot update another workspace profile', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin']);
    $action = app(UpdateBusinessProfileSection::class);
    $baseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);

    expect(fn () => $action->handle(
        $owner->currentAccount,
        $business,
        $outsider,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['city' => 'Dallas']),
        $baseline,
    ))->toThrow(AuthorizationException::class)
        ->and($business->refresh()->city)->not->toBe('Dallas')
        ->and($business->profileVersions()->count())->toBe(0);
});

test('conflict keep my edits rebases only the local patch over the newest values', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin', 'county' => 'Travis']);
    $action = app(UpdateBusinessProfileSection::class);
    $component = Livewire::actingAs($owner)->test(ProfileEditor::class, ['section' => 'location_structure']);
    $newerBaseline = $action->baselineEnvelope($business, BusinessProfileSection::LocationStructure);

    $action->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        locationProfileValues($business, ['city' => 'Dallas', 'county' => 'Dallas']),
        $newerBaseline,
    );

    $component
        ->set('values.city', 'Houston')
        ->call('save')
        ->assertHasErrors('profile')
        ->assertSee('Keep my edits')
        ->assertSee('Current City')
        ->call('keepMyEdits')
        ->assertHasNoErrors()
        ->assertSet('conflicts', []);

    expect($business->refresh()->city)->toBe('Houston')
        ->and($business->county)->toBe('Dallas');
});

test('viewing the profile hub and history never creates a legacy baseline', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->get(route('business.edit'))
        ->assertOk()
        ->assertSee('Keep your operating facts current');
    $this->get(route('business.profile.history'))
        ->assertOk()
        ->assertSee('No versioned profile update has been recorded yet');

    expect($business->profileVersions()->count())->toBe(0);
});
