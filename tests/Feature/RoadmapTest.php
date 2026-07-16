<?php

use App\Enums\RoadmapStatus;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Models\User;
use App\Services\RoadmapBuilder;
use App\Services\RoadmapItem;

test('guests are redirected to the login page', function () {
    $this->get(route('roadmap'))->assertRedirect(route('login'));
});

test('users without a business are redirected to onboarding', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('roadmap'))->assertRedirect(route('onboarding.welcome'));
});

test('the roadmap renders every phase with items', function () {
    $user = User::factory()->create();
    Business::factory()->operatingDba()->for($user)->create();
    $this->actingAs($user);

    $this->get(route('roadmap'))
        ->assertOk()
        ->assertSeeInOrder([
            'Foundation', 'Legal setup', 'Taxes', 'Banking', 'Accounting',
            'Payroll', 'Owner pay', 'Branding', 'Advertising', 'Growth readiness',
        ])
        ->assertSee('not legal, tax, payroll, or accounting advice');
});

test('an ein marks the ein item complete and unsure marks it needs info', function () {
    $withEin = Business::factory()->make(['user_id' => 1, 'has_ein' => YesNoUnsure::Yes]);
    $unsureEin = Business::factory()->make(['user_id' => 1, 'has_ein' => YesNoUnsure::Unsure]);

    $builder = new RoadmapBuilder;

    $einStatus = fn (Business $business): RoadmapStatus => $builder->build($business)
        ->first(fn (RoadmapItem $item): bool => $item->key === 'get-ein')
        ->status;

    expect($einStatus($withEin))->toBe(RoadmapStatus::Complete)
        ->and($einStatus($unsureEin))->toBe(RoadmapStatus::NeedsInfo);
});

test('payroll items are not applicable without employees and open with them', function () {
    $noEmployees = Business::factory()->make(['user_id' => 1, 'employee_count' => 0]);
    $withEmployees = Business::factory()->withEmployees()->make(['user_id' => 1, 'has_payroll' => false]);

    $builder = new RoadmapBuilder;

    $payrollStatus = fn (Business $business): RoadmapStatus => $builder->build($business)
        ->first(fn (RoadmapItem $item): bool => $item->key === 'payroll-provider')
        ->status;

    expect($payrollStatus($noEmployees))->toBe(RoadmapStatus::NotApplicable)
        ->and($payrollStatus($withEmployees))->toBe(RoadmapStatus::ToDo);
});

test('next actions only contain open items and respect the limit', function () {
    $business = Business::factory()->startingFromScratch()->make(['user_id' => 1]);

    $actions = new RoadmapBuilder()->nextActions($business);

    expect($actions)->toHaveCount(5)
        ->and($actions->every(fn (RoadmapItem $item): bool => $item->isOpen()))->toBeTrue();
});

test('required items come before recommended ones within a phase', function () {
    $business = Business::factory()->startingFromScratch()->make(['user_id' => 1]);

    $legalSetup = new RoadmapBuilder()->buildGrouped($business)->get('legal_setup');

    $weights = $legalSetup->map(fn (RoadmapItem $item): int => $item->priority->weight())->all();
    $sorted = $weights;
    sort($sorted);

    expect($weights)->toBe($sorted);
});
