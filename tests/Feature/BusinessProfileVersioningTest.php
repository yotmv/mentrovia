<?php

use App\Enums\AccountRole;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Livewire\Business\ProfileHistory;
use App\Models\Business;
use App\Models\BusinessProfile;
use App\Models\BusinessProfileVersion;
use App\Models\User;
use App\Services\BusinessProfileContext;
use App\Services\BusinessProfileFingerprint;
use App\Services\BusinessProfileSnapshot;
use App\Services\BusinessProfileValuePresenter;
use App\Services\BusinessProfileVersionService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('profile snapshots are deterministic and exclude account identity and timestamps', function () {
    $business = Business::factory()->create();
    BusinessProfile::factory()->for($business)->create([
        'question_key' => 'zeta.answer',
        'answer_value' => 'Second',
        'confidence' => 'user_confirmed',
    ]);
    BusinessProfile::factory()->for($business)->create([
        'question_key' => 'alpha.answer',
        'answer_value' => 'First',
        'confidence' => null,
    ]);

    $snapshot = app(BusinessProfileSnapshot::class)->capture($business->fresh());
    $fingerprints = app(BusinessProfileFingerprint::class);
    $reordered = [
        'profile_answers' => $snapshot['profile_answers'],
        'business' => array_reverse($snapshot['business'], true),
        'schema_version' => $snapshot['schema_version'],
    ];

    expect($snapshot['schema_version'])->toBe(BusinessProfileSnapshot::SCHEMA_VERSION)
        ->and(array_column($snapshot['profile_answers'], 'question_key'))->toBe(['alpha.answer', 'zeta.answer'])
        ->and($snapshot['business'])->not->toHaveKeys(['id', 'account_id', 'user_id', 'created_at', 'updated_at', 'profile_revision'])
        ->and($fingerprints->make($snapshot))->toBe($fingerprints->make($reordered));
});

test('a blank optional fingerprint key falls back to the application key', function () {
    config([
        'security.profile_fingerprint_key' => '',
        'app.key' => 'stable-application-key',
    ]);
    $fingerprints = app(BusinessProfileFingerprint::class);

    expect($fingerprints->make(['company' => 'Mentrovia']))
        ->toBe(hash_hmac('sha256', '{"company":"Mentrovia"}', 'stable-application-key'));
});

test('profile fingerprinting rejects blank optional and application keys', function () {
    config([
        'security.profile_fingerprint_key' => '',
        'app.key' => '',
    ]);

    expect(fn () => app(BusinessProfileFingerprint::class)->make(['company' => 'Mentrovia']))
        ->toThrow(RuntimeException::class, 'A profile fingerprint key is required.');
});

test('unrelated profile answers affect advisor context but never marketing context', function () {
    $business = Business::factory()->create();
    $contexts = app(BusinessProfileContext::class);
    $marketingBefore = $contexts->marketingFingerprint($business);
    $advisorBefore = $contexts->advisorFingerprint($business);

    BusinessProfile::factory()->for($business)->create([
        'question_key' => 'banking_setup.tax-reserve',
        'answer_value' => 'done',
    ]);
    BusinessProfile::factory()->for($business)->create([
        'question_key' => 'future.unrelated.fact',
        'answer_value' => 'private answer',
    ]);
    $business->unsetRelation('profileAnswers');

    expect($contexts->marketingFingerprint($business))->toBe($marketingBefore)
        ->and($contexts->advisorFingerprint($business))->not->toBe($advisorBefore)
        ->and($contexts->marketing($business)['profile_answers'])->toBe([]);
});

test('profile versions encrypt snapshots and safe metadata at rest', function () {
    $user = User::factory()->create();
    $business = Business::factory()->for($user)->create(['name' => 'Cipher Test Company']);

    $version = DB::transaction(function () use ($business, $user): BusinessProfileVersion {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);

        return app(BusinessProfileVersionService::class)->recordLocked(
            $locked,
            BusinessProfileVersionSource::Onboarding,
            $user,
            ['name'],
            [BusinessProfileSection::CompanyBasics],
            ['selected_count' => 1],
        );
    });
    $raw = DB::table('business_profile_versions')->where('id', $version->id)->first();

    expect($raw->snapshot)->not->toContain('Cipher Test Company')
        ->and($raw->source_metadata)->not->toContain('selected_count')
        ->and($version->fresh()->snapshot['business']['name'])->toBe('Cipher Test Company')
        ->and($version->source_metadata)->toBe(['selected_count' => 1]);
});

test('profile revision is monotonic while no-op recording creates no version', function () {
    $business = Business::factory()->create();
    $versions = app(BusinessProfileVersionService::class);

    DB::transaction(function () use ($business, $versions): void {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);
        $versions->ensureBaselineLocked($locked);
        $versions->recordLocked($locked, BusinessProfileVersionSource::Manual, null, [], []);
    });

    expect($business->profileVersions()->count())->toBe(1)
        ->and($business->refresh()->profile_revision)->toBe(1);

    DB::transaction(function () use ($business, $versions): void {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);
        $versions->ensureBaselineLocked($locked);
        $locked->update(['industry' => 'Updated industry']);
        $versions->recordLocked(
            $locked,
            BusinessProfileVersionSource::Manual,
            null,
            ['industry'],
            [BusinessProfileSection::CompanyBasics],
        );
    });

    expect($business->profileVersions()->pluck('revision')->all())->toBe([1, 2])
        ->and($business->refresh()->profile_revision)->toBe(2);
});

test('an existing baseline fails closed when live facts bypass immutable versioning', function () {
    $business = Business::factory()->create(['industry' => 'Bookkeeping']);
    $versions = app(BusinessProfileVersionService::class);

    DB::transaction(function () use ($business, $versions): void {
        $versions->ensureBaselineLocked(Business::query()->lockForUpdate()->findOrFail($business->id));
    });
    Business::query()->whereKey($business->id)->update(['industry' => 'Unversioned mutation']);

    expect(fn () => DB::transaction(function () use ($business, $versions): void {
        $versions->ensureBaselineLocked(Business::query()->lockForUpdate()->findOrFail($business->id));
    }))->toThrow(LogicException::class, 'does not match its latest immutable version')
        ->and($business->profileVersions()->count())->toBe(1)
        ->and($business->refresh()->profile_revision)->toBe(1);
});

test('an unchanged live profile repairs tracker drift without creating a revision', function () {
    $business = Business::factory()->create();
    $versions = app(BusinessProfileVersionService::class);
    $version = DB::transaction(fn () => $versions->ensureBaselineLocked(Business::query()->lockForUpdate()->findOrFail($business->id)));
    Business::query()->whereKey($business->id)->update([
        'profile_revision' => 0,
        'profile_fingerprint' => null,
    ]);

    DB::transaction(function () use ($business, $versions): void {
        $versions->ensureBaselineLocked(Business::query()->lockForUpdate()->findOrFail($business->id));
    });

    expect($business->refresh()->profile_revision)->toBe($version->revision)
        ->and($business->profile_fingerprint)->toBe($version->fingerprint)
        ->and($business->profileVersions()->count())->toBe(1);
});

test('the legacy backfill is bounded and idempotent', function () {
    $inactive = Business::factory()->create();
    $inactive->account->forceFill(['erasure_started_at' => now()])->save();
    $alreadyVersioned = Business::factory()->create();
    DB::transaction(function () use ($alreadyVersioned): void {
        app(BusinessProfileVersionService::class)->ensureBaselineLocked(
            Business::query()->lockForUpdate()->findOrFail($alreadyVersioned->id),
        );
    });
    $businesses = Business::factory()->count(3)->create();

    $this->artisan('business-profiles:backfill-versions', ['--limit' => 2, '--chunk' => 1])->assertSuccessful();
    expect(BusinessProfileVersion::query()->count())->toBe(3);

    $this->artisan('business-profiles:backfill-versions', ['--limit' => 2, '--chunk' => 2])->assertSuccessful();
    $this->artisan('business-profiles:backfill-versions', ['--limit' => 2, '--chunk' => 2])->assertSuccessful();

    expect(BusinessProfileVersion::query()->count())->toBe(4)
        ->and($businesses->every(fn (Business $business): bool => $business->refresh()->profile_revision === 1))->toBeTrue()
        ->and($inactive->profileVersions()->count())->toBe(0)
        ->and($alreadyVersioned->profileVersions()->count())->toBe(1);
});

test('profile values use friendly enum date boolean and null presentation', function () {
    $presenter = app(BusinessProfileValuePresenter::class);

    expect($presenter->present('stage', 'existing_with_employees'))->toBe('Existing business with employees')
        ->and($presenter->present('started_on', '2026-07-15'))->toBe('Jul 15, 2026')
        ->and($presenter->present('has_payroll', true))->toBe('Yes')
        ->and($presenter->present('first_employee_on', null))->toBe('—');
});

test('profile history keeps null attribution after member erasure and cascades with the workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $business = Business::factory()->for($owner)->create();
    $version = DB::transaction(function () use ($business, $member): BusinessProfileVersion {
        $locked = Business::query()->lockForUpdate()->findOrFail($business->id);

        return app(BusinessProfileVersionService::class)->recordLocked(
            $locked,
            BusinessProfileVersionSource::Manual,
            $member,
            ['industry'],
            [BusinessProfileSection::CompanyBasics],
        );
    });

    $personalAccount = $member->accounts()->where('accounts.id', '!=', $account->id)->first();
    $member->forceFill(['current_account_id' => null])->save();
    $account->members()->detach($member);
    $personalAccount?->delete();
    $member->delete();
    expect($version->fresh()->created_by_user_id)->toBeNull();

    $account->delete();
    expect(BusinessProfileVersion::query()->whereKey($version->id)->doesntExist())->toBeTrue();
});

test('profile history paginates and compares page boundaries against the adjacent older revision', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner)->create();

    foreach (range(1, 12) as $revision) {
        BusinessProfileVersion::factory()->forBusiness($business, $owner)->create([
            'revision' => $revision,
            'changed_field_keys' => ['name'],
            'snapshot' => [
                'schema_version' => 1,
                'business' => ['name' => 'Revision '.$revision],
                'profile_answers' => [],
            ],
        ]);
    }

    $component = Livewire::actingAs($owner)->test(ProfileHistory::class);
    $firstPage = $component->get('timeline');

    expect($firstPage)->toHaveCount(10)
        ->and($firstPage[9]['version']->revision)->toBe(3)
        ->and($firstPage[9]['changes'][0]['before'])->toBe('Revision 2');

    $component->call('nextPage', 'profile-history-page');
    $secondPage = $component->get('timeline');

    expect($secondPage)->toHaveCount(2)
        ->and($secondPage[0]['version']->revision)->toBe(2)
        ->and($secondPage[0]['changes'][0]['before'])->toBe('Revision 1');
});
