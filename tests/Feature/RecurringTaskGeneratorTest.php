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

test('task applicability uses fresh locked business facts instead of a stale caller instance', function () {
    $business = Business::factory()->create([
        'employee_count' => 0,
        'uses_contractors' => false,
        'sells_taxable_goods' => 'no',
        'sells_taxable_services' => 'no',
    ]);
    $staleBusiness = $business->fresh();
    Business::query()->whereKey($business->id)->update(['employee_count' => 2]);

    app(RecurringTaskGenerator::class)->generateFor($staleBusiness);

    expect($business->tasks()->where('category', TaskCategory::Payroll)->count())->toBe(3);
});

test('a month day task due today does not roll into the next year', function () {
    Carbon::setTestNow('2026-01-31 10:00:00');
    $business = Business::factory()->create();

    app(RecurringTaskGenerator::class)->generateFor($business);

    expect($business->tasks()
        ->whereHas('template', fn ($query) => $query->where('slug', 'yearly-compliance-sweep'))
        ->sole()
        ->due_on?->toDateString())->toBe('2026-01-31');
});

test('inapplicable tasks retire and later reactivate without losing completion history', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->withEmployees(2)->for($owner)->create();
    $generator = app(RecurringTaskGenerator::class);
    $generator->generateFor($business);
    $task = $business->tasks()->where('category', TaskCategory::Payroll)->firstOrFail();
    $task->forceFill(['completed_at' => now(), 'notes' => 'Preserve this evidence.'])->save();
    TaskCompletion::factory()->for($task, 'task')->for($business)->create(['completed_for' => $task->due_on]);

    $business->forceFill(['employee_count' => 0, 'first_employee_on' => null, 'has_payroll' => false])->save();
    $generator->generateFor($business->refresh());

    expect($task->refresh()->is_active)->toBeFalse()
        ->and($task->retired_at)->not->toBeNull()
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->notes)->toBe('Preserve this evidence.')
        ->and($task->completions()->count())->toBe(1);

    $this->actingAs($owner)
        ->get(route('tasks.index', ['period' => 'all']))
        ->assertOk()
        ->assertDontSee($task->title);
    $this->patch(route('tasks.update', $task), ['completed' => false, 'notes' => 'Unauthorized rewrite'])
        ->assertForbidden();

    $business->update(['employee_count' => 1]);
    $generator->generateFor($business->refresh());

    expect($task->refresh()->is_active)->toBeTrue()
        ->and($task->retired_at)->toBeNull()
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->completions()->count())->toBe(1);
});

test('reactivating an overdue incomplete task refreshes its due date and preserves history', function () {
    $business = Business::factory()->withEmployees(2)->create();
    $generator = app(RecurringTaskGenerator::class);
    $generator->generateFor($business);
    $task = $business->tasks()
        ->whereHas('template', fn ($query) => $query->where('slug', 'weekly-payroll-hours-review'))
        ->firstOrFail();
    $task->forceFill([
        'due_on' => '2026-06-28',
        'completed_at' => null,
        'notes' => 'Carry this open evidence forward.',
    ])->save();
    TaskCompletion::factory()->for($task, 'task')->for($business)->create([
        'completed_for' => '2026-05-31',
    ]);

    $business->update(['employee_count' => 0]);
    $generator->generateFor($business->fresh());
    Carbon::setTestNow('2026-08-01 10:00:00');
    $business->update(['employee_count' => 1]);
    $generator->generateFor($business->fresh());

    expect($task->refresh()->is_active)->toBeTrue()
        ->and($task->due_on?->toDateString())->toBe('2026-08-02')
        ->and($task->completed_at)->toBeNull()
        ->and($task->notes)->toBe('Carry this open evidence forward.')
        ->and($task->completions()->count())->toBe(1);
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

test('completed tasks roll into a later due period while preserving completion history', function () {
    $business = Business::factory()->create();
    $generator = app(RecurringTaskGenerator::class);

    $generator->generateFor($business);

    $task = $business->tasks()
        ->whereHas('template', fn ($query) => $query->where('slug', 'monthly-bookkeeping-close'))
        ->firstOrFail();
    $originalDueDate = $task->due_on;
    $task->forceFill(['completed_at' => now(), 'notes' => 'July is closed.'])->save();
    TaskCompletion::create([
        'business_task_id' => $task->id,
        'business_id' => $business->id,
        'completed_for' => $originalDueDate,
        'completed_at' => now(),
        'notes' => 'July is closed.',
    ]);

    Carbon::setTestNow('2026-08-01 10:00:00');
    $generator->generateFor($business->refresh());

    expect($task->refresh()->due_on?->toDateString())->toBe('2026-08-31')
        ->and($task->completed_at)->toBeNull()
        ->and($task->notes)->toBeNull()
        ->and($task->completions()->count())->toBe(1)
        ->and($task->completions()->sole()->completed_for->toDateString())->toBe('2026-07-31');
});

test('incomplete overdue tasks are never advanced', function () {
    $business = Business::factory()->create();
    $generator = app(RecurringTaskGenerator::class);

    $generator->generateFor($business);

    $task = $business->tasks()
        ->whereHas('template', fn ($query) => $query->where('slug', 'monthly-bookkeeping-close'))
        ->firstOrFail();
    $task->forceFill(['due_on' => '2026-06-30', 'completed_at' => null, 'notes' => 'Still waiting.'])->save();

    Carbon::setTestNow('2026-08-01 10:00:00');
    $generator->generateFor($business->refresh());

    expect($task->refresh()->due_on?->toDateString())->toBe('2026-06-30')
        ->and($task->completed_at)->toBeNull()
        ->and($task->notes)->toBe('Still waiting.');
});

test('repeated generation does not advance a reopened task twice', function () {
    $business = Business::factory()->create();
    $generator = app(RecurringTaskGenerator::class);

    $generator->generateFor($business);

    $task = $business->tasks()
        ->whereHas('template', fn ($query) => $query->where('slug', 'monthly-bookkeeping-close'))
        ->firstOrFail();
    $task->forceFill(['completed_at' => now(), 'notes' => 'July is closed.'])->save();
    TaskCompletion::factory()->for($task, 'task')->for($business)->create([
        'completed_for' => $task->due_on,
    ]);

    Carbon::setTestNow('2026-08-01 10:00:00');
    $generator->generateFor($business->refresh());
    $generator->generateFor($business->refresh());

    expect($task->refresh()->due_on?->toDateString())->toBe('2026-08-31')
        ->and($task->completed_at)->toBeNull()
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
        ->call('next')
        ->call('next')
        ->call('next')
        ->call('next')
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->business->tasks()->count())->toBe(11);
});
