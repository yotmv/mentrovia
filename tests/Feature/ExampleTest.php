<?php

use App\Models\User;

test('returns a successful response', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
});

test('the landing page shows mentrovia branding and calls to action', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Know exactly what your Texas business needs next.')
        ->assertSee('Get started')
        ->assertSee('not legal, tax, payroll, or accounting advice')
        ->assertDontSee('Laravel has an incredibly rich ecosystem');
});

test('authenticated visitors see a dashboard link on the landing page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Go to dashboard');
});
