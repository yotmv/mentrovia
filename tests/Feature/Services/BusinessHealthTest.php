<?php

use App\Enums\LegalStructure;
use App\Enums\RiskFlag;
use App\Enums\YesNoUnsure;
use App\Models\Business;
use App\Services\BusinessHealth;

test('an operating business without a business bank account is flagged for commingling', function () {
    $business = Business::factory()->operatingDba()->make(['user_id' => 1, 'has_business_bank' => false]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::PersonalBankCommingling);
});

test('a business with a business bank account is not flagged for commingling', function () {
    $business = Business::factory()->operatingDba()->make(['user_id' => 1, 'has_business_bank' => true]);

    expect(new BusinessHealth()->riskFlags($business))->not->toContain(RiskFlag::PersonalBankCommingling);
});

test('possibly taxable sales without a permit are flagged as a permit gap', function (YesNoUnsure $goods) {
    $business = Business::factory()->make([
        'user_id' => 1,
        'sells_taxable_goods' => $goods,
        'sells_taxable_services' => YesNoUnsure::No,
        'has_sales_tax_permit' => YesNoUnsure::No,
    ]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::SalesTaxPermitGap);
})->with([
    'sells taxable goods' => YesNoUnsure::Yes,
    'unsure about taxable goods' => YesNoUnsure::Unsure,
]);

test('no permit gap flag when nothing taxable is sold or the permit exists', function (array $attributes) {
    $business = Business::factory()->make(['user_id' => 1, ...$attributes]);

    expect(new BusinessHealth()->riskFlags($business))->not->toContain(RiskFlag::SalesTaxPermitGap);
})->with([
    'nothing taxable' => [[
        'sells_taxable_goods' => YesNoUnsure::No,
        'sells_taxable_services' => YesNoUnsure::No,
    ]],
    'permit already held' => [[
        'sells_taxable_goods' => YesNoUnsure::Yes,
        'has_sales_tax_permit' => YesNoUnsure::Yes,
    ]],
]);

test('employees without payroll are flagged', function () {
    $business = Business::factory()->withEmployees()->make(['user_id' => 1, 'has_payroll' => false]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::EmployeesWithoutPayroll);
});

test('employees with payroll are not flagged', function () {
    $business = Business::factory()->withEmployees()->make(['user_id' => 1, 'has_payroll' => true]);

    expect(new BusinessHealth()->riskFlags($business))->not->toContain(RiskFlag::EmployeesWithoutPayroll);
});

test('an unsure legal structure is flagged as unclear', function () {
    $business = Business::factory()->make(['user_id' => 1, 'legal_structure' => LegalStructure::Unsure]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::UnclearLegalStructure);
});

test('operating before an entity decision is flagged', function () {
    $business = Business::factory()->operatingDba()->make(['user_id' => 1, 'legal_structure' => LegalStructure::NotStarted]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::OperatingWithoutEntityDecision);
});

test('an operating business without an ein is flagged', function () {
    $business = Business::factory()->operatingDba()->make(['user_id' => 1, 'has_ein' => YesNoUnsure::No]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::MissingEin);
});

test('an operating business without bookkeeping is flagged', function () {
    $business = Business::factory()->operatingDba()->make(['user_id' => 1, 'has_bookkeeping' => false]);

    expect(new BusinessHealth()->riskFlags($business))->toContain(RiskFlag::NoBookkeeping);
});

test('a fully set up business has no risk flags and a perfect score', function () {
    $business = Business::factory()->formalEntity()->make([
        'user_id' => 1,
        'dba_status' => YesNoUnsure::Yes,
        'has_ein' => YesNoUnsure::Yes,
        'has_sales_tax_permit' => YesNoUnsure::Yes,
        'has_business_bank' => true,
        'has_bookkeeping' => true,
        'has_payroll' => true,
    ]);

    $health = new BusinessHealth;

    expect($health->riskFlags($business))->toBeEmpty()
        ->and($health->setupScore($business))->toBe(100)
        ->and($health->missingSetupItems($business))->toBeEmpty();
});

test('setup score stays within bounds and reflects missing items', function () {
    $business = Business::factory()->startingFromScratch()->make([
        'user_id' => 1,
        'legal_structure' => LegalStructure::NotStarted,
        'has_ein' => YesNoUnsure::No,
        'has_business_bank' => false,
        'has_bookkeeping' => false,
        'dba_status' => YesNoUnsure::Unsure,
    ]);

    $health = new BusinessHealth;
    $score = $health->setupScore($business);

    expect($score)->toBeGreaterThanOrEqual(0)
        ->and($score)->toBeLessThan(50)
        ->and($health->missingSetupItems($business))->toContain('Open a business bank account');
});

test('payroll is excluded from scoring when there are no employees', function () {
    $business = Business::factory()->make([
        'user_id' => 1,
        'employee_count' => 0,
        'has_payroll' => false,
    ]);

    expect(new BusinessHealth()->missingSetupItems($business))->not->toContain('Set up payroll');
});
