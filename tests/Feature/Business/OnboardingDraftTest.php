<?php

use App\Actions\Business\FinalizeBusinessIntake;
use App\Actions\Business\SaveOnboardingDraft;
use App\Enums\AccountRole;
use App\Enums\BusinessOnboardingTrack;
use App\Livewire\Business\Intake;
use App\Models\Business;
use App\Models\OnboardingDraft;
use App\Models\RoadmapPlan;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\RoadmapPlanSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('a workspace saves one encrypted versioned onboarding draft', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $action = app(SaveOnboardingDraft::class);

    $draft = $action->handle(
        $account,
        $user,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['desired_name' => 'Secret Bluebonnet', 'industry' => 'Landscaping'],
        null,
    );

    $rawPayload = DB::table('onboarding_drafts')->where('id', $draft->id)->value('payload');

    expect($draft->revision)->toBe(1)
        ->and($draft->schema_version)->toBe(1)
        ->and($draft->payload['desired_name'])->toBe('Secret Bluebonnet')
        ->and($draft->expires_at->isSameDay(now()->addDays(180)))->toBeTrue()
        ->and($rawPayload)->toBeString()->not->toContain('Secret Bluebonnet');

    $updated = $action->handle(
        $account,
        $user,
        BusinessOnboardingTrack::NewCompany,
        2,
        ['city' => 'Austin'],
        1,
    );

    expect($updated->revision)->toBe(2)
        ->and($updated->current_step)->toBe(2)
        ->and($updated->payload)->toMatchArray([
            'desired_name' => 'Secret Bluebonnet',
            'industry' => 'Landscaping',
            'city' => 'Austin',
        ])
        ->and(OnboardingDraft::query()->where('account_id', $account->id)->count())->toBe(1);
});

test('stale draft revisions and cross workspace writes are rejected', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $account = $owner->currentAccount;
    $action = app(SaveOnboardingDraft::class);

    $action->handle(
        $account,
        $owner,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['desired_name' => 'Original', 'industry' => 'Consulting'],
        null,
    );

    expect(fn () => $action->handle(
        $account,
        $owner,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['industry' => 'Changed'],
        0,
    ))->toThrow(ValidationException::class)
        ->and(fn () => $action->handle(
            $account,
            $otherUser,
            BusinessOnboardingTrack::NewCompany,
            1,
            ['industry' => 'Stolen'],
            1,
        ))->toThrow(AuthorizationException::class);

    expect($account->onboardingDraft->payload['industry'])->toBe('Consulting')
        ->and($account->onboardingDraft->revision)->toBe(1);
});

test('draft normalization rejects malformed scalar answers', function (array $values) {
    $user = User::factory()->create();

    expect(fn () => app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::NewCompany,
        1,
        $values,
        null,
    ))->toThrow(ValidationException::class);

    expect(OnboardingDraft::query()->where('account_id', $user->current_account_id)->doesntExist())->toBeTrue();
})->with([
    'invalid boolean' => [['uses_contractors' => 'sometimes']],
    'decimal count' => [['employee_count' => 1.5]],
]);

test('draft attribution is nulled when a saving member is deleted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $memberPersonalAccount = $member->currentAccount;
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();
    $memberPersonalAccount->delete();

    $draft = app(SaveOnboardingDraft::class)->handle(
        $account,
        $member,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['desired_name' => 'Persistent Draft', 'industry' => 'Retail'],
        null,
    );

    $account->members()->detach($member);
    $member->delete();

    expect($draft->refresh()->last_saved_by_user_id)->toBeNull()
        ->and($draft->payload['desired_name'])->toBe('Persistent Draft');
});

test('finalization creates one business and roadmap then removes the draft', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $values = establishedOnboardingValues();
    $draft = app(SaveOnboardingDraft::class)->handle(
        $account,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        3,
        $values,
        null,
    );

    $result = app(FinalizeBusinessIntake::class)->handle(
        $account,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        $values,
        $draft->revision,
        null,
    );

    expect($result['created'])->toBeTrue()
        ->and($result['conflict'])->toBeFalse()
        ->and($result['business']->name)->toBe('Bluebonnet Services LLC')
        ->and($result['business']->desired_name)->toBeNull()
        ->and(Business::query()->where('account_id', $account->id)->count())->toBe(1)
        ->and(RoadmapPlan::query()->where('business_id', $result['business']->id)->count())->toBe(1)
        ->and(OnboardingDraft::query()->where('account_id', $account->id)->doesntExist())->toBeTrue();

    expect(fn () => app(FinalizeBusinessIntake::class)->handle(
        $account,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        $values,
        $draft->revision,
        null,
    ))->toThrow(ValidationException::class, 'Another person finished this company profile first.');

    expect(Business::query()->where('account_id', $account->id)->count())->toBe(1)
        ->and(RoadmapPlan::query()->where('business_id', $result['business']->id)->count())->toBe(1);
});

test('a stale finalizer receives a visible conflict and no success toast', function () {
    $user = User::factory()->create();
    app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        3,
        establishedOnboardingValues(),
        null,
    );
    $this->actingAs($user);
    $winner = Livewire::test(Intake::class);
    $stale = Livewire::test(Intake::class);

    $winner
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.plan-ready', absolute: false));

    $stale
        ->call('save')
        ->assertHasErrors(['draftRevision'])
        ->assertSee('Another person finished this company profile first.')
        ->assertNotDispatched('toast-show')
        ->assertNoRedirect();
});

test('an established company sees baseline-specific copy immediately after finalizing', function () {
    $user = User::factory()->create();
    app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        3,
        establishedOnboardingValues(),
        null,
    );
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSessionHas('onboarding.finalized_track', BusinessOnboardingTrack::EstablishedCompany->value)
        ->assertRedirect(route('onboarding.plan-ready', absolute: false));

    app(CurrentAccount::class)->forget();

    $this->get(route('onboarding.plan-ready'))
        ->assertOk()
        ->assertSee('established company’s operating baseline');
});

test('a failed finalize rolls back the business and retains the draft', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $values = establishedOnboardingValues();
    $draft = app(SaveOnboardingDraft::class)->handle(
        $account,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        3,
        $values,
        null,
    );
    $synchronizer = Mockery::mock(RoadmapPlanSynchronizer::class);
    $synchronizer->shouldReceive('syncAfterAuthorizedProfileMutation')
        ->once()
        ->andThrow(new RuntimeException('Roadmap failed'));
    app()->instance(RoadmapPlanSynchronizer::class, $synchronizer);

    expect(fn () => app(FinalizeBusinessIntake::class)->handle(
        $account,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        $values,
        $draft->revision,
        null,
    ))->toThrow(RuntimeException::class, 'Roadmap failed');

    expect(Business::query()->where('account_id', $account->id)->doesntExist())->toBeTrue()
        ->and(OnboardingDraft::query()->whereKey($draft->id)->exists())->toBeTrue();
});

test('draft saves are unavailable after a business exists', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->for($user->currentAccount)->create();

    expect(fn () => app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['desired_name' => 'Should Not Save'],
        null,
    ))->toThrow(ValidationException::class);

    expect(OnboardingDraft::query()->where('account_id', $user->current_account_id)->doesntExist())->toBeTrue();
});

test('workspace deletion cascades drafts and the retention command prunes only eligible expiry', function () {
    $expired = OnboardingDraft::factory()->create(['expires_at' => now()->subMinute()]);
    $future = OnboardingDraft::factory()->create(['expires_at' => now()->addMinute()]);
    $erasing = OnboardingDraft::factory()->create(['expires_at' => now()->subMinute()]);
    $erasing->account->forceFill(['erasure_started_at' => now()])->save();

    $this->artisan('onboarding-drafts:prune', ['--chunk' => 1])
        ->assertSuccessful();

    expect(OnboardingDraft::query()->whereKey($expired->id)->doesntExist())->toBeTrue()
        ->and(OnboardingDraft::query()->whereKey($future->id)->exists())->toBeTrue()
        ->and(OnboardingDraft::query()->whereKey($erasing->id)->exists())->toBeTrue();

    $future->account->delete();

    expect(OnboardingDraft::query()->whereKey($future->id)->doesntExist())->toBeTrue();
});

/** @return array<string, bool|int|string|null> */
function establishedOnboardingValues(): array
{
    return [
        'name' => 'Bluebonnet Services LLC',
        'desired_name' => null,
        'dba_status' => 'no',
        'industry' => 'Consulting',
        'started_on' => '2024-01-15',
        'operates_in_texas' => 'yes',
        'city' => 'Austin',
        'county' => 'Travis',
        'location_type' => 'online_only',
        'address' => null,
        'legal_structure' => 'llc',
        'owner_count' => 1,
        'employee_count' => 0,
        'uses_contractors' => false,
        'first_employee_on' => null,
        'sells_taxable_goods' => 'no',
        'sells_taxable_services' => 'unsure',
        'has_sales_tax_permit' => 'no',
        'has_ein' => 'yes',
        'annual_revenue_range' => '25k_to_100k',
        'monthly_revenue_range' => '1k_to_5k',
        'first_sale_on' => '2024-02-01',
        'has_business_bank' => true,
        'has_bookkeeping' => true,
        'has_payroll' => false,
        'filing_confidence' => 'some_knowledge',
    ];
}
