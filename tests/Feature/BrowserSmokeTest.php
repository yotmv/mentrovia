<?php

use App\Models\Business;
use App\Models\User;

test('the public landing page is accessible to guests', function () {
    $this->get(route('home'))->assertOk();
});

test('guests are redirected from every authenticated route', function () {
    $routes = [
        'dashboard',
        'business.intake',
        'roadmap',
        'projects.index',
        'profile.edit',
        'appearance.edit',
    ];

    foreach ($routes as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

test('authenticated users can load every main page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('business.intake'))->assertOk();
    $this->get(route('projects.index'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('appearance.edit'))->assertOk();
});

test('roadmap redirects to intake without a business profile', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('roadmap'))->assertRedirect(route('business.intake'));
});

test('roadmap loads for a user with a business profile', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('roadmap'))->assertOk();
});
