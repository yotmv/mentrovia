<?php

use App\Enums\TaskCategory;
use App\Livewire\Business\Intake;
use App\Models\Business;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Services\RecurringTaskGenerator;
use Database\Seeders\KnowledgeArticleSeeder;
use Database\Seeders\RecurringTaskTemplateSeeder;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-07-08 10:00:00');

    $this->seed(KnowledgeArticleSeeder::class);
    $this->seed(RecurringTaskTemplateSeeder::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('starting from scratch businesses receive the base recurring task set', function () {
    $business = Business::factory()->startingFromScratch()->create([
        'sells_taxable_goods' => 'no',
        'sells_taxable_services' => 'no',
    ]);

    app(RecurringTaskGenerator::class)->generateFor($business);

    expect($business->tasks()->pluck('title')->all())->toEqualCanonicalizing([
        'Review weekly business admin',
        'Close monthly bookkeeping',
        'Review quarterly estimated taxes',
        'Run annual compliance sweep',
    ]);
});

test('sales tax exposed businesses receive sales tax tasks', function () {
    $business = Business::factory()->create([
        'sells_taxable_goods' => 'yes',
        'sells_taxable_services' => 'no',
    ]);

    app(RecurringTaskGenerator::class)->generateFor($business);

    expect($business->tasks()->where('category', TaskCategory::SalesTax)->pluck('title')->all())
        ->toEqualCanonicalizing([
            'Check sales tax reserve',
            'Review monthly sales tax liability',
        ]);
});

test('employee and contractor profile changes add newly applicable tasks idempotently', function () {
    $business = Business::factory()->create([
        'employee_count' => 0,
        'uses_contractors' => false,
        'sells_taxable_goods' => 'no',
        'sells_taxable_services' => 'no',
    ]);

    $generator = app(RecurringTaskGenerator::class);

    $generator->generateFor($business);

    expect($business->tasks()->count())->toBe(4);

    $business->forceFill([
        'employee_count' => 2,
        'uses_contractors' => true,
    ])->save();

    $generator->generateFor($business->refresh());
    $generator->generateFor($business->refresh());

    expect($business->tasks()->where('category', TaskCategory::Payroll)->pluck('title')->all())
        ->toEqualCanonicalizing([
            'Review payroll hours',
            'Confirm quarterly payroll filings',
            'Confirm employee year-end forms',
        ])
        ->and($business->tasks()->where('category', TaskCategory::Contractors)->pluck('title')->all())
        ->toEqualCanonicalizing([
            'Review contractor payments',
            'Prepare contractor form review',
        ])
        ->and($business->tasks()->count())->toBe(9);
});

test('regeneration preserves completed task history', function () {
    $business = Business::factory()->create();
    $generator = app(RecurringTaskGenerator::class);

    $generator->generateFor($business);

    $task = $business->tasks()->firstOrFail();
    $task->forceFill([
        'completed_at' => now(),
        'notes' => 'Done for this period.',
    ])->save();

    TaskCompletion::create([
        'business_task_id' => $task->id,
        'business_id' => $business->id,
        'completed_for' => $task->due_on,
        'completed_at' => now(),
        'notes' => 'Done for this period.',
    ]);

    $generator->generateFor($business->refresh());

    expect($task->refresh()->completed_at)->not->toBeNull()
        ->and($task->notes)->toBe('Done for this period.')
        ->and($task->completions()->count())->toBe(1);
});

test('saving intake creates applicable recurring tasks', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Intake::class)
        ->set('name', 'Bluebonnet Lawn Care')
        ->set('dba_status', 'yes')
        ->set('industry', 'Landscaping')
        ->set('city', 'Austin')
        ->set('county', 'Travis')
        ->set('location_type', 'mobile_service')
        ->set('legal_structure', 'sole_proprietor')
        ->set('owner_count', 1)
        ->set('employee_count', 1)
        ->set('uses_contractors', true)
        ->set('sells_taxable_goods', 'yes')
        ->set('sells_taxable_services', 'no')
        ->set('has_sales_tax_permit', 'no')
        ->set('has_ein', 'no')
        ->set('annual_revenue_range', '25k_to_100k')
        ->set('monthly_revenue_range', '1k_to_5k')
        ->set('first_sale_on', '2025-03-01')
        ->set('filing_confidence', 'some_knowledge')
        ->set('step', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->business->tasks()->count())->toBe(11);
});
