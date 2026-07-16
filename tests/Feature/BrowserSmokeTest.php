<?php

use App\Models\Business;
use App\Models\User;

test('the public landing page is accessible to guests', function () {
    $this->get(route('home'))->assertOk();
});

test('guests are redirected from every authenticated route', function () {
    $routes = [
        'dashboard',
        'feedback.create',
        'onboarding.welcome',
        'onboarding.plan-ready',
        'business.overview',
        'business.intake',
        'business.edit',
        'business.not-supported',
        'guides.index',
        'roadmap',
        'grow',
        'owner-pay',
        'projects.index',
        'profile.edit',
        'appearance.edit',
    ];

    foreach ($routes as $route) {
        $this->get(route($route))->assertRedirect(route('login'));
    }
});

test('authenticated users can load every main page once they have a business profile', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk();
    $this->get(route('feedback.create'))->assertOk();
    $this->get(route('business.intake'))->assertRedirect(route('business.edit'));
    $this->get(route('business.overview'))->assertOk();
    $this->get(route('guides.index'))->assertOk();
    $this->get(route('guides.show', 'formation'))->assertOk();
    $this->get(route('grow'))->assertOk();
    $this->get(route('projects.index'))->assertOk();
    $this->get(route('profile.edit'))->assertOk();
    $this->get(route('appearance.edit'))->assertOk();
});

test('roadmap redirects to onboarding without a business profile', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('roadmap'))->assertRedirect(route('onboarding.welcome'));
});

test('roadmap loads for a user with a business profile', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('roadmap'))->assertOk();
});

test('owner pay loads for a user with a business profile', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('owner-pay'))->assertOk();
});
