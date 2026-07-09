<?php

use App\Enums\LegalStructure;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Models\User;

test('the sidebar shows every primary nav item in the final v1 order', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([
            'Overview',
            'Company Profile',
            'Guidance',
            'Roadmap',
            'Tasks',
            'Advisor',
            'Knowledge',
            'Marketing',
            'Projects',
            'Branding',
            'Advertising',
        ])
        ->assertSee('Dashboard')
        ->assertSee('Settings');
});

test('every sidebar link targets a resolvable route', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'))->assertOk();

    foreach ([
        route('dashboard'),
        route('business.intake'),
        route('roadmap'),
        route('tasks.index'),
        route('advisor'),
        route('knowledge.articles.index'),
        route('projects.index'),
        route('branding'),
        route('advertising'),
        route('profile.edit'),
    ] as $href) {
        $response->assertSee($href, escape: false);
    }
});

test('every sidebar destination loads for a user with a business profile', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    foreach ([
        'dashboard',
        'business.intake',
        'roadmap',
        'tasks.index',
        'advisor',
        'knowledge.articles.index',
        'projects.index',
        'branding',
        'advertising',
        'profile.edit',
    ] as $route) {
        $this->get(route($route))->assertOk();
    }
});

test('admin nav items are hidden from non-admin users', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Review Queue')
        ->assertDontSee('Knowledge Admin');
});

test('admin nav items are visible to admins and their destinations load', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Review Queue')
        ->assertSee('Knowledge Admin');

    $this->get(route('admin.knowledge.reviews.index'))->assertOk();
    $this->get(route('admin.knowledge.articles.index'))->assertOk();
});

test('the roadmap cross-links into the branding, advertising, tasks, advisor, and knowledge modules', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertSee(route('branding'), escape: false)
        ->assertSee(route('advertising'), escape: false)
        ->assertSee(route('tasks.index'), escape: false)
        ->assertSee(route('advisor'), escape: false)
        ->assertSee(route('knowledge.articles.index', ['category' => 'sales_tax']), escape: false)
        ->assertSee('Generate a brand kit')
        ->assertSee('Generate your 30-day marketing plan')
        ->assertSee('Open your task list');
});

test('dashboard risk flags link into the module that resolves them', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create([
        'first_sale_on' => now()->subMonth(),
        'legal_structure' => LegalStructure::Unsure,
        'has_business_bank' => false,
        'has_bookkeeping' => false,
        'has_ein' => YesNoUnsure::No,
    ]);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Open banking checklist')
        ->assertSee(route('banking-setup'), escape: false)
        ->assertSee('Read the bookkeeping guidance')
        ->assertSee(route('knowledge.articles.index', ['category' => 'accounting']), escape: false)
        ->assertSee('Update your company profile')
        ->assertSee('Ask the Advisor');
});

test('dashboard next actions link into their module guides', function () {
    $user = User::factory()->create();
    Business::factory()->startingFromScratch()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('The next work, in order.')
        ->assertSee('Generate name ideas in Branding')
        ->assertSee(route('branding'), escape: false);
});
