<?php

use App\Actions\Business\ImportBusinessProfile;
use App\Actions\Business\UpdateBusinessProfileSection;
use App\Enums\AccountRole;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Livewire\Business\ProfileImport;
use App\Models\Business;
use App\Models\BusinessProfileVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('only workspace managers can open the existing business import', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    Business::factory()->for($owner)->create();

    $this->actingAs($owner)->get(route('business.profile.import'))->assertOk()->assertSee('Import a Mentrovia CSV');
    $this->actingAs($member)->get(route('business.profile.import'))->assertForbidden();
});

test('manager CSV preview is nonmutating and apply trusts only its encrypted envelope', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin', 'county' => 'Travis']);
    $this->actingAs($owner);

    $component = Livewire::test(ProfileImport::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'secret-company-name.csv',
            "city,county,unknown_field\nDallas,Dallas,raw-secret-cell\n",
        ))
        ->call('previewCsv')
        ->assertHasNoErrors()
        ->assertSet('preview.city.imported', 'Dallas')
        ->assertSee('Current')
        ->assertSee('Imported')
        ->assertSee('Result')
        ->assertSee('Unknown column');

    expect($business->refresh()->city)->toBe('Austin')
        ->and($business->profileVersions()->count())->toBe(0);

    $component
        ->set('preview.city.imported', 'Browser Tampering')
        ->set('selections.county', false)
        ->call('apply')
        ->assertHasNoErrors()
        ->assertSet('preview', []);

    $version = $business->profileVersions()->latest('revision')->firstOrFail();
    $raw = DB::table('business_profile_versions')->where('id', $version->id)->first();

    expect($business->refresh()->city)->toBe('Dallas')
        ->and($business->county)->not->toBe('Dallas')
        ->and($business->profileVersions()->count())->toBe(2)
        ->and($version->source)->toBe(BusinessProfileVersionSource::CsvImport)
        ->and($version->source_metadata['selected_field_keys'])->toBe(['city'])
        ->and($version->source_metadata['source_fingerprint'])->toHaveLength(64)
        ->and($raw->source_metadata)->not->toContain('secret-company-name.csv')
        ->and($raw->source_metadata)->not->toContain('raw-secret-cell')
        ->and($raw->source_metadata)->not->toContain('Browser Tampering');
});

test('CSV derived employee invariants are mandatory and recorded in one revision', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->withEmployees(2)->for($owner)->create(['has_payroll' => true]);

    $component = Livewire::actingAs($owner)->test(ProfileImport::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'company.csv',
            "employee_count\n0\n",
        ))
        ->call('previewCsv')
        ->assertSee('Required consistency changes')
        ->set('selections.employee_count', false)
        ->assertDontSee('Required consistency changes')
        ->set('selections.employee_count', true)
        ->assertSee('Required consistency changes')
        ->call('apply')
        ->assertHasNoErrors();

    expect($business->refresh()->employee_count)->toBe(0)
        ->and($business->first_employee_on)->toBeNull()
        ->and($business->has_payroll)->toBeFalse()
        ->and($business->profileVersions()->count())->toBe(2)
        ->and($business->profileVersions()->latest('revision')->firstOrFail()->changed_field_keys)
        ->toEqualCanonicalizing(['employee_count', 'first_employee_on', 'has_payroll', 'stage'])
        ->and($business->profileVersions()->latest('revision')->firstOrFail()->sections)
        ->toEqualCanonicalizing(['company_basics', 'operations_readiness', 'people_obligations']);
});

test('CSV cannot introduce payroll or an employee date while employee count remains zero', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create([
        'city' => 'Austin',
        'employee_count' => 0,
        'first_employee_on' => null,
        'has_payroll' => false,
    ]);

    Livewire::actingAs($owner)->test(ProfileImport::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'company.csv',
            "city,first_employee_on,has_payroll\nDallas,2026-01-01,yes\n",
        ))
        ->call('previewCsv')
        ->assertSee('Required')
        ->call('apply')
        ->assertHasNoErrors();

    $latest = $business->profileVersions()->latest('revision')->firstOrFail();

    expect($business->refresh()->city)->toBe('Dallas')
        ->and($business->first_employee_on)->toBeNull()
        ->and($business->has_payroll)->toBeFalse()
        ->and($latest->changed_field_keys)->toEqualCanonicalizing(['city', 'stage']);
});

test('a matching CSV has no derived rows and cannot create a no-op revision', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create([
        'city' => 'Austin',
        'employee_count' => 0,
        'first_employee_on' => null,
        'has_payroll' => false,
    ]);

    $component = Livewire::actingAs($owner)->test(ProfileImport::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent('company.csv', "city\nAustin\n"))
        ->call('previewCsv')
        ->assertHasNoErrors()
        ->assertSet('hasApplicableChanges', false)
        ->assertSee('This CSV already matches the current profile.')
        ->assertDontSee('Required consistency changes')
        ->call('apply')
        ->assertHasErrors('csvUpload');

    expect($component->get('previewRows'))->toHaveCount(1)
        ->and($business->profileVersions()->count())->toBe(0);
});

test('an invariant-only CSV explains why selected employee values cannot apply', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create([
        'employee_count' => 0,
        'first_employee_on' => null,
        'has_payroll' => false,
    ]);

    Livewire::actingAs($owner)->test(ProfileImport::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'company.csv',
            "employee_count,has_payroll\n0,yes\n",
        ))
        ->call('previewCsv')
        ->assertHasNoErrors()
        ->assertSet('hasApplicableChanges', false)
        ->assertSet('hasInvariantBlockedSelections', true)
        ->assertSee('Employee consistency rules prevent the selected payroll or first employee values')
        ->assertDontSee('This CSV already matches the current profile.')
        ->call('apply')
        ->assertHasErrors('csvUpload');

    expect($business->refresh()->has_payroll)->toBeFalse()
        ->and($business->profileVersions()->count())->toBe(0);
});

test('CSV apply rejects a preview after a versioned profile change', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create(['city' => 'Austin']);
    $component = Livewire::actingAs($owner)->test(ProfileImport::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent('company.csv', "city\nDallas\n"))
        ->call('previewCsv')
        ->assertHasNoErrors();
    $updates = app(UpdateBusinessProfileSection::class);
    $baseline = $updates->baselineEnvelope($business, BusinessProfileSection::LocationStructure);
    $updates->handle(
        $owner->currentAccount,
        $business,
        $owner,
        BusinessProfileSection::LocationStructure,
        [
            'city' => $business->city,
            'county' => 'Williamson',
            'state' => $business->state,
            'location_type' => $business->location_type->value,
            'address' => $business->address,
            'legal_structure' => $business->legal_structure->value,
            'tax_classification' => $business->tax_classification,
        ],
        $baseline,
    );

    $component->call('apply')
        ->assertHasErrors('csvUpload')
        ->assertSee('profile changed after this preview');

    expect($business->refresh()->city)->toBe('Austin')
        ->and(BusinessProfileVersion::query()->count())->toBe(2);
});

test('a tampered CSV preview envelope is rejected', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner)->create();

    expect(fn () => app(ImportBusinessProfile::class)->handle(
        $owner->currentAccount,
        $owner->business,
        $owner,
        'tampered',
        ['city' => true],
    ))->toThrow(ValidationException::class);
});
