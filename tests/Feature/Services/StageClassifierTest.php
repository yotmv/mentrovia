<?php

use App\Enums\BusinessStage;
use App\Enums\LegalStructure;
use App\Enums\MonthlyRevenueRange;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Services\StageClassifier;

test('classifies a brand-new business as starting from scratch', function () {
    $business = Business::factory()->startingFromScratch()->make(['user_id' => 1]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::StartingFromScratch);
});

test('classifies an operating sole proprietor as existing dba', function () {
    $business = Business::factory()->operatingDba()->make(['user_id' => 1]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::ExistingDba);
});

test('classifies a formal entity without employees as existing entity', function () {
    $business = Business::factory()->formalEntity()->make(['user_id' => 1]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::ExistingEntity);
});

test('classifies any business with employees as existing with employees', function () {
    $business = Business::factory()->withEmployees()->make(['user_id' => 1]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::ExistingWithEmployees);
});

test('employees take precedence over a formal entity', function () {
    $business = Business::factory()->formalEntity()->withEmployees()->make(['user_id' => 1]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::ExistingWithEmployees);
});

test('a first employee date counts as having employees', function () {
    $business = Business::factory()->make([
        'user_id' => 1,
        'employee_count' => 0,
        'first_employee_on' => now()->subMonths(3),
    ]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::ExistingWithEmployees);
});

test('operating signals classify as existing dba', function (array $attributes) {
    $business = Business::factory()->startingFromScratch()->make([
        'user_id' => 1,
        ...$attributes,
    ]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::ExistingDba);
})->with([
    'first sale recorded' => [['first_sale_on' => '2025-06-01']],
    'monthly revenue' => [['monthly_revenue_range' => MonthlyRevenueRange::From1KTo5K]],
    'active dba' => [['dba_status' => YesNoUnsure::Yes]],
]);

test('an unsure structure with no activity still classifies as starting from scratch', function () {
    $business = Business::factory()->startingFromScratch()->make([
        'user_id' => 1,
        'legal_structure' => LegalStructure::Unsure,
    ]);

    expect(new StageClassifier()->classify($business))->toBe(BusinessStage::StartingFromScratch);
});
