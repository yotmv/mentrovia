<?php

use App\Enums\LegalStructure;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Models\User;

test('the sidebar expresses the primary user journey', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder([
            'Your business', 'Today', 'Business', 'Plan and operate', 'Plan', 'Tasks', 'Guides',
            'Ask and learn', 'Advisor', 'Knowledge', 'Grow', 'Growth workspace',
        ])
        ->assertSee('Settings');
});

test('every sidebar link targets a resolvable route', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'))->assertOk();

    foreach ([
        route('dashboard'), route('business.overview'), route('roadmap'), route('tasks.index'),
        route('guides.index'), route('advisor'), route('knowledge.articles.index'), route('grow'), route('profile.edit'),
    ] as $href) {
        $response->assertSee($href, escape: false);
    }
});

test('every sidebar destination loads for a user with a business profile', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    foreach (['dashboard', 'business.overview', 'roadmap', 'tasks.index', 'guides.index', 'advisor', 'knowledge.articles.index', 'grow', 'profile.edit'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $this->get(route('guides.show', 'formation'))->assertOk();
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
    Business::factory()->operatingDba()->for($admin)->create();
    $this->actingAs($admin);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Review Queue')
        ->assertSee('Knowledge Admin');

    $this->get(route('admin.knowledge.reviews.index'))->assertOk();
    $this->get(route('admin.knowledge.articles.index'))->assertOk();
});

test('the roadmap cross-links into growth, tasks, advisor, and guides', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertSee(route('branding'), escape: false)
        ->assertSee(route('advertising'), escape: false)
        ->assertSee(route('tasks.index'), escape: false)
        ->assertSee(route('advisor'), escape: false)
        ->assertSee(route('guides.show', 'sales-tax'), escape: false)
        ->assertSee('Generate a brand kit')
        ->assertSee('Generate your 30-day marketing plan')
        ->assertSee('Open your task list');
});

test('dashboard risk flags link into the guide that resolves them', function () {
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
        ->assertSee('Open the banking guide')
        ->assertSee(route('guides.show', 'banking'), escape: false)
        ->assertSee('Open the bookkeeping guide')
        ->assertSee(route('guides.show', 'bookkeeping'), escape: false)
        ->assertSee('Open the formation guide');
});

test('dashboard next actions retain their contextual destinations', function () {
    $user = User::factory()->create();
    Business::factory()->startingFromScratch()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Do this next')
        ->assertSee('Generate name ideas in Branding')
        ->assertSee(route('branding'), escape: false);
});
