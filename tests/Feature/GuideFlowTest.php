<?php

use App\Models\Business;
use App\Models\User;

test('guides require authentication and a business profile', function () {
    $this->get(route('guides.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('guides.index'))
        ->assertRedirect(route('onboarding.welcome'));
});

test('the guide hub exposes all operational playbooks', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('guides.index'))
        ->assertOk()
        ->assertSee('Formation and DBA')
        ->assertSee('Sales-tax readiness')
        ->assertSee('Bookkeeping setup')
        ->assertSee('First hire, payroll, and contractors')
        ->assertSee('Business banking')
        ->assertSee('Owner pay');
});

test('guide topics render their profile-aware checklist or retain legacy guide compatibility', function (string $topic) {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('guides.show', $topic));

    if (in_array($topic, ['banking', 'owner-pay'], true)) {
        $response->assertRedirect(route($topic === 'banking' ? 'banking-setup' : 'owner-pay'));
    } else {
        $response->assertOk()->assertSee('Your next checklist');
    }
})->with(['formation', 'sales-tax', 'bookkeeping', 'payroll', 'banking', 'owner-pay']);
