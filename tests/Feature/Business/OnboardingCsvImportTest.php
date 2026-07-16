<?php

use App\Actions\Business\SaveOnboardingDraft;
use App\Enums\BusinessOnboardingTrack;
use App\Livewire\Business\Intake;
use App\Models\User;
use App\Services\BusinessProfileCsvImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('the CSV template is authenticated and contains the canonical headers', function () {
    $this->get(route('business.intake.template'))->assertRedirect(route('login'));

    $response = $this->actingAs(User::factory()->create())
        ->get(route('business.intake.template'));

    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain('business_name,industry,operates_in_texas');
});

test('the CSV parser normalizes recognized fields and warns on unknown headers', function () {
    $parsed = app(BusinessProfileCsvImporter::class)->parse(
        "business_name,operates_in_texas,legal_structure,employee_count,uses_contractors,unknown_field\n".
        "Bluebonnet LLC,yes,LLC,3,yes,ignored\n",
    );

    expect($parsed['proposals'])->toMatchArray([
        'name' => 'Bluebonnet LLC',
        'operates_in_texas' => 'yes',
        'legal_structure' => 'llc',
        'employee_count' => 3,
        'uses_contractors' => true,
    ])->and($parsed['recognized_count'])->toBe(5)
        ->and($parsed['unknown_count'])->toBe(1)
        ->and($parsed['warnings'])->toHaveCount(1)
        ->and($parsed['fingerprint'])->toHaveLength(64);
});

test('CSV parsing rejects unsafe or ambiguous content', function (string $contents) {
    expect(fn () => app(BusinessProfileCsvImporter::class)->parse($contents))
        ->toThrow(ValidationException::class);
})->with([
    'formula' => "business_name\n=HYPERLINK(\"https://example.test\")\n",
    'control character' => "business_name\nBad\tName\n",
    'C1 control character' => "business_name\nBad\u{0085}Name\n",
    'bidirectional format override' => "business_name\nBad\u{202E}Name\n",
    'duplicate header' => "business_name,business_name\nOne,Two\n",
    'extra company row' => "business_name\nOne\nTwo\n",
    'invalid UTF-8' => "business_name\n\xC3\x28\n",
    'oversized file' => fn (): string => "business_name\n".str_repeat('a', 131073)."\n",
]);

test('CSV preview does not mutate the draft and apply uses the encrypted preview values', function () {
    $user = User::factory()->create();
    $draft = app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        1,
        ['name' => 'Current Company'],
        null,
    );
    $this->actingAs($user);
    $upload = UploadedFile::fake()->createWithContent(
        'company.csv',
        "business_name,employee_count,has_payroll,unknown\nImported Company,0,yes,discard me\n",
    );

    $component = Livewire::test(Intake::class)
        ->set('csvUpload', $upload)
        ->call('previewCsv')
        ->assertHasNoErrors()
        ->assertSet('name', 'Current Company')
        ->assertSet('draftRevision', 1)
        ->assertSet('importPreview.name.imported', 'Imported Company')
        ->assertSee('Current')
        ->assertSee('Imported')
        ->assertSee('Result');

    expect($draft->refresh()->revision)->toBe(1)
        ->and($draft->payload['name'])->toBe('Current Company');

    $component
        ->set('importPreview.name.imported', 'Tampered Browser Value')
        ->call('applyCsv')
        ->assertHasNoErrors()
        ->assertSet('draftRevision', 2)
        ->assertSet('name', 'Imported Company')
        ->assertSet('has_payroll', false)
        ->assertSet('importPreview', []);

    $payload = $draft->refresh()->payload;

    expect($payload['name'])->toBe('Imported Company')
        ->and($payload['employee_count'])->toBe(0)
        ->and($payload['has_payroll'])->toBeFalse()
        ->and(json_encode($payload))->not->toContain('discard me')
        ->and(json_encode($payload))->not->toContain('Tampered Browser Value');
});

test('CSV apply rejects a preview after another writer advances the revision', function () {
    $user = User::factory()->create();
    $draft = app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        1,
        ['name' => 'Current Company'],
        null,
    );
    $this->actingAs($user);
    $component = Livewire::test(Intake::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'company.csv',
            "business_name\nImported Company\n",
        ))
        ->call('previewCsv')
        ->assertHasNoErrors();

    app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        1,
        ['industry' => 'Updated elsewhere'],
        $draft->revision,
    );

    $component
        ->set('name', 'Unsaved Local Name')
        ->call('applyCsv')
        ->assertHasErrors(['draftRevision'])
        ->assertSet('name', 'Unsaved Local Name')
        ->assertSee('This saved profile changed elsewhere. Reload it before saving again.');

    expect($draft->refresh()->payload['name'])->toBe('Current Company')
        ->and($draft->revision)->toBe(2);
});

test('an invalid CSV clears a previous valid preview before validation or parsing', function () {
    $user = User::factory()->create();
    app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        1,
        ['name' => 'Current Company'],
        null,
    );
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'valid.csv',
            "business_name\nImported Company\n",
        ))
        ->call('previewCsv')
        ->assertSet('importPreview.name.imported', 'Imported Company')
        ->set('csvUpload', UploadedFile::fake()->createWithContent(
            'invalid.csv',
            "business_name\n=UNSAFE()\n",
        ))
        ->call('previewCsv')
        ->assertHasErrors(['csvUpload'])
        ->assertSee('CSV cells may not contain control characters')
        ->assertSee('role="alert"', false)
        ->assertSet('importPreview', [])
        ->assertSet('importSelections', [])
        ->assertSet('importWarnings', [])
        ->assertSet('importEnvelope', '');
});
