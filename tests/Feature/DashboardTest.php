<?php

use App\Models\Business;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('users without a business see the intake call to action', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tell us about your business')
        ->assertDontSee('Business setup score');
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
        ->assertSee('The next work, in order.')
        ->assertSee('Personal and business funds may be mixed')
        ->assertSee('not legal, tax, payroll, or accounting advice');
});
