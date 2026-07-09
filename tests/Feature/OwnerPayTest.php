<?php

use App\Enums\LegalStructure;
use App\Enums\OwnerPayFit;
use App\Enums\OwnerPayMethod;
use App\Models\Business;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Services\OwnerPayGuide;
use App\Services\OwnerPayOption;

test('guests are redirected to the login page', function () {
    $this->get(route('owner-pay'))->assertRedirect(route('login'));
});

test('users without a business are redirected to the intake wizard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('owner-pay'))->assertRedirect(route('business.intake'));
});

test('a sole proprietor sees draws as typical and salary as unavailable', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create(['legal_structure' => LegalStructure::SoleProprietor]);
    $this->actingAs($user);

    $this->get(route('owner-pay'))
        ->assertOk()
        ->assertSee('Owner draw')
        ->assertSee('Typical for you')
        ->assertSee('quarterly estimated taxes')
        ->assertSee('Not options for your setup')
        ->assertSee('generally cannot put themselves on W-2 payroll')
        ->assertSee('Questions for your CPA')
        ->assertSee('not legal, tax, payroll, or accounting advice');
});

test('a partnership sees guaranteed payments and no employee salary', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create([
        'legal_structure' => LegalStructure::Partnership,
        'owner_count' => 2,
    ]);
    $this->actingAs($user);

    $this->get(route('owner-pay'))
        ->assertOk()
        ->assertSee('Guaranteed payment')
        ->assertSee('partnership agreement')
        ->assertSee('Partners generally cannot be W-2 employees');
});

test('an s corporation sees reasonable salary first and then distributions', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create(['legal_structure' => LegalStructure::SCorporation]);
    $this->actingAs($user);

    $this->get(route('owner-pay'))
        ->assertOk()
        ->assertSee('W-2 salary')
        ->assertSee('reasonable W-2 salary')
        ->assertSee('Distribution')
        ->assertSee('audit trigger');
});

test('a c corporation sees salary, dividends, and retained earnings', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create(['legal_structure' => LegalStructure::CCorporation]);
    $this->actingAs($user);

    $this->get(route('owner-pay'))
        ->assertOk()
        ->assertSee('W-2 salary')
        ->assertSee('Dividend')
        ->assertSee('Retained earnings')
        ->assertSee('double taxation');
});

test('an undecided structure prompts profile clarification and professional review', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create(['legal_structure' => LegalStructure::Unsure]);
    $this->actingAs($user);

    $this->get(route('owner-pay'))
        ->assertOk()
        ->assertSee('Decide on a legal structure first')
        ->assertSee('Update your company profile')
        ->assertSee('attorney or CPA')
        ->assertSee('Depends on structure')
        ->assertSee(route('business.intake'), escape: false);
});

test('a single-member llc is guided like a sole proprietor with an election question', function () {
    $business = Business::factory()->make([
        'user_id' => 1,
        'legal_structure' => LegalStructure::Llc,
        'owner_count' => 1,
    ]);

    $advice = new OwnerPayGuide()->advise($business);

    $draw = collect($advice->options)->firstOrFail(
        fn (OwnerPayOption $option): bool => $option->method === OwnerPayMethod::OwnerDraw,
    );

    expect($advice->needsStructureDecision)->toBeFalse()
        ->and($draw->fit)->toBe(OwnerPayFit::Typical)
        ->and($advice->structureSummary)->toContain('single-member LLC')
        ->and(implode(' ', $advice->cpaQuestions))->toContain('S or C election');
});

test('a multi-member llc is guided like a partnership', function () {
    $business = Business::factory()->make([
        'user_id' => 1,
        'legal_structure' => LegalStructure::Llc,
        'owner_count' => 2,
    ]);

    $advice = new OwnerPayGuide()->advise($business);

    $guaranteed = collect($advice->options)->firstOrFail(
        fn (OwnerPayOption $option): bool => $option->method === OwnerPayMethod::GuaranteedPayment,
    );

    expect($guaranteed->fit)->toBe(OwnerPayFit::Typical)
        ->and($advice->structureSummary)->toContain('multi-member LLC');
});

test('related knowledge articles are linked when seeded', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create(['legal_structure' => LegalStructure::SCorporation]);
    $article = KnowledgeArticle::factory()->create([
        'title' => 'S Corporation Owner Pay Basics',
        'slug' => 's-corporation-owner-pay-basics',
    ]);
    $this->actingAs($user);

    $this->get(route('owner-pay'))
        ->assertOk()
        ->assertSee('Related knowledge')
        ->assertSee($article->title)
        ->assertSee(route('knowledge.articles.show', $article->slug), escape: false);
});

test('the roadmap owner pay item links to the owner pay page', function () {
    $user = User::factory()->create();
    Business::factory()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertSee('Compare your owner-pay options')
        ->assertSee(route('owner-pay'), escape: false);
});
