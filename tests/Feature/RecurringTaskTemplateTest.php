<?php

use App\Enums\TaskCategory;
use App\Enums\TaskConfidence;
use App\Enums\TaskFrequency;
use App\Models\Business;
use App\Models\RecurringTaskTemplate;
use Database\Seeders\KnowledgeArticleSeeder;
use Database\Seeders\RecurringTaskTemplateSeeder;

beforeEach(function () {
    $this->seed(KnowledgeArticleSeeder::class);
    $this->seed(RecurringTaskTemplateSeeder::class);
});

test('recurring task templates are seeded idempotently from recurring knowledge articles', function () {
    expect(RecurringTaskTemplate::count())->toBe(11);

    $this->seed(RecurringTaskTemplateSeeder::class);

    expect(RecurringTaskTemplate::count())->toBe(11);

    RecurringTaskTemplate::with('sourceArticle')->get()->each(function (RecurringTaskTemplate $template) {
        expect($template->sourceArticle)->not->toBeNull()
            ->and($template->sourceArticle->category->value)->toBe('recurring_tasks');
    });
});

test('templates cast task fields to enums and arrays', function () {
    $template = RecurringTaskTemplate::where('slug', 'monthly-bookkeeping-close')->firstOrFail();

    expect($template->category)->toBe(TaskCategory::Bookkeeping)
        ->and($template->frequency)->toBe(TaskFrequency::Monthly)
        ->and($template->confidence)->toBe(TaskConfidence::High)
        ->and($template->applies_to)->toHaveKey('employees')
        ->and($template->due_rule)->toBe(['type' => 'end_of_month']);
});

test('template applicability can target profile attributes', function () {
    $startingBusiness = Business::factory()->startingFromScratch()->create([
        'sells_taxable_goods' => 'no',
        'sells_taxable_services' => 'no',
    ]);
    $salesTaxBusiness = Business::factory()->create([
        'sells_taxable_goods' => 'yes',
        'sells_taxable_services' => 'no',
    ]);
    $employeeBusiness = Business::factory()->withEmployees()->create();
    $contractorBusiness = Business::factory()->create(['uses_contractors' => true]);

    $salesTaxTemplate = RecurringTaskTemplate::where('slug', 'weekly-sales-tax-reserve-check')->firstOrFail();
    $payrollTemplate = RecurringTaskTemplate::where('slug', 'weekly-payroll-hours-review')->firstOrFail();
    $contractorTemplate = RecurringTaskTemplate::where('slug', 'monthly-contractor-payment-review')->firstOrFail();
    $generalTemplate = RecurringTaskTemplate::where('slug', 'weekly-admin-review')->firstOrFail();

    expect($generalTemplate->appliesTo($startingBusiness))->toBeTrue()
        ->and($salesTaxTemplate->appliesTo($startingBusiness))->toBeFalse()
        ->and($salesTaxTemplate->appliesTo($salesTaxBusiness))->toBeTrue()
        ->and($payrollTemplate->appliesTo($startingBusiness))->toBeFalse()
        ->and($payrollTemplate->appliesTo($employeeBusiness))->toBeTrue()
        ->and($contractorTemplate->appliesTo($startingBusiness))->toBeFalse()
        ->and($contractorTemplate->appliesTo($contractorBusiness))->toBeTrue();
});
