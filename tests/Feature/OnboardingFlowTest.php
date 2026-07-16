<?php

use App\Livewire\Business\Intake;
use App\Models\Business;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from onboarding routes', function () {
    foreach (['onboarding.welcome', 'onboarding.plan-ready', 'business.overview', 'business.not-supported'] as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

test('new users are sent from Today to onboarding welcome', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertRedirect(route('onboarding.welcome'));

    $this->get(route('onboarding.welcome'))
        ->assertOk()
        ->assertSee('Start your company profile');
});

test('users with a profile can review their plan-ready summary and business overview', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create(['name' => 'Bluebonnet Lawn Care']);
    $this->actingAs($user);

    $this->get(route('onboarding.welcome'))->assertRedirect(route('dashboard'));
    $this->get(route('onboarding.plan-ready'))
        ->assertOk()
        ->assertSee('Your first three actions')
        ->assertSee('Bluebonnet Lawn Care');
    $this->get(route('business.overview'))
        ->assertOk()
        ->assertSee('Profile completeness');
});

test('an out-of-Texas intake response opens the supported-location explanation', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Intake::class)
        ->set('desired_name', 'Outside Texas Company')
        ->set('industry', 'Consulting')
        ->call('next')
        ->set('operates_in_texas', 'no')
        ->call('next')
        ->assertRedirect(route('business.not-supported', absolute: false));
});
