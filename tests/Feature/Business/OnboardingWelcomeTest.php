<?php

use App\Actions\Business\SaveOnboardingDraft;
use App\Enums\BusinessOnboardingTrack;
use App\Livewire\Business\Intake;
use App\Livewire\Onboarding\Welcome;
use App\Models\OnboardingDraft;
use App\Models\User;
use Livewire\Livewire;

test('the welcome chooser starts the selected account-owned track', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Welcome::class)
        ->assertSee('I’m starting a company')
        ->assertSee('I already run a company')
        ->call('start', BusinessOnboardingTrack::EstablishedCompany->value)
        ->assertHasNoErrors()
        ->assertRedirect(route('business.intake', absolute: false));

    $draft = OnboardingDraft::query()->sole();

    expect($draft->account_id)->toBe($user->current_account_id)
        ->and($draft->track)->toBe(BusinessOnboardingTrack::EstablishedCompany)
        ->and($draft->current_step)->toBe(1)
        ->and($draft->revision)->toBe(1);
});

test('welcome and intake resume the saved track step and answers', function () {
    $user = User::factory()->create();
    app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::EstablishedCompany,
        2,
        ['name' => 'Resumable Company', 'industry' => 'Retail'],
        null,
    );
    $this->actingAs($user);

    Livewire::test(Welcome::class)
        ->assertSee('Resume your company profile')
        ->assertSee('Already running a company')
        ->assertSee('Step 2 of 3');

    Livewire::test(Intake::class)
        ->assertSet('track', BusinessOnboardingTrack::EstablishedCompany->value)
        ->assertSet('step', 2)
        ->assertSet('draftRevision', 1)
        ->assertSet('name', 'Resumable Company')
        ->assertSee('Step 2 of 3');
});

test('start over requires the current revision before deleting progress', function () {
    $user = User::factory()->create();
    $draft = app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['desired_name' => 'First Name'],
        null,
    );
    $this->actingAs($user);
    $staleComponent = Livewire::test(Welcome::class);

    app(SaveOnboardingDraft::class)->handle(
        $user->currentAccount,
        $user,
        BusinessOnboardingTrack::NewCompany,
        1,
        ['desired_name' => 'Changed Name'],
        $draft->revision,
    );

    $staleComponent
        ->call('startOver', 1)
        ->assertHasErrors(['draftRevision']);

    expect($draft->refresh()->revision)->toBe(2);

    Livewire::test(Welcome::class)
        ->call('startOver', 2)
        ->assertHasNoErrors();

    expect(OnboardingDraft::query()->whereKey($draft->id)->doesntExist())->toBeTrue();
});

test('save and exit persists partial answers and returns to welcome', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->set('desired_name', 'Saved For Later')
        ->set('industry', 'Landscaping')
        ->call('saveAndExit')
        ->assertHasNoErrors()
        ->assertRedirect(route('onboarding.welcome', absolute: false));

    $draft = $user->currentAccount->onboardingDraft()->sole();

    expect($draft->payload)->toMatchArray([
        'desired_name' => 'Saved For Later',
        'industry' => 'Landscaping',
    ])->and($draft->revision)->toBe(1);
});
