<?php

use App\Models\Business;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with a business can visit Today', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('users without a business are guided into onboarding', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertRedirect(route('onboarding.welcome'));
});

test('users with a business see their score, risk flags, and next actions', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create([
        'name' => 'Bluebonnet Lawn Care',
        'has_business_bank' => false,
    ]);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Bluebonnet Lawn Care')
        ->assertSee('Business setup score')
        ->assertSee('Risk flags')
        ->assertSee('Do this next')
        ->assertSee('The next work, in order.')
        ->assertSee('Personal and business funds may be mixed')
        ->assertSee('not legal, tax, payroll, or accounting advice');
});

test('Today gives roadmap actions without direct links concrete Tasks and Advisor fallbacks', function () {
    $business = Business::factory()->create();

    $this->actingAs($business->user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Open Tasks'))
        ->assertSee(__('Ask Advisor'))
        ->assertSee(route('tasks.index'), escape: false)
        ->assertSee(route('advisor'), escape: false)
        ->assertSee(__('Open Tasks for :action', ['action' => __('Form your entity or confirm your registration')]));
});

test('Today preserves concrete roadmap links when an action already has one', function () {
    $business = Business::factory()->startingFromScratch()->create();

    $this->actingAs($business->user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee(__('Generate name ideas in Branding'))
        ->assertSee(route('branding'), escape: false);
});
