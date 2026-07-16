<?php

use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Livewire\Settings\Ai;
use App\Livewire\Settings\AiTrust;
use App\Models\AiAccountSetting;
use App\Models\AiModelPreference;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Ai\AiTrustCenterReadModel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('a successful AI controls save creates exactly one content-free immutable audit', function () {
    $manager = User::factory()->create();

    Livewire::actingAs($manager)->test(Ai::class)
        ->set('monthlyUsdLimit', '25.50')
        ->set('maxConcurrency', 3)
        ->call('saveSettings')
        ->assertHasNoErrors();

    $audit = AiOperationAudit::query()->sole();

    expect($audit->event)->toBe(AiAuditEvent::ControlsChanged)
        ->and($audit->account_id)->toBe($manager->current_account_id)
        ->and($audit->actor_user_id)->toBe($manager->id)
        ->and($audit->changed_fields)->toBe(['max_concurrency', 'monthly_usd_limit'])
        ->and($audit->before_fingerprint)->toHaveLength(64)
        ->and($audit->after_fingerprint)->toHaveLength(64)
        ->and($audit->getAttributes())->not->toContain('25.50');
});

test('a failed AI controls save creates no control audit', function () {
    $manager = User::factory()->create();

    Livewire::actingAs($manager)->test(Ai::class)
        ->set('byokEnabled', true)
        ->call('saveSettings')
        ->assertHasErrors('byokEnabled');

    expect(AiOperationAudit::query()->exists())->toBeFalse();
});

test('only managers can view the account scoped trust center and navigation', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();

    $this->actingAs($owner)->get(route('ai.trust'))
        ->assertOk()
        ->assertSee('AI trust center');

    $this->actingAs($member)->get(route('ai.trust'))->assertForbidden();
    $this->actingAs($member)->get(route('profile.edit'))->assertDontSee('AI trust center');
});

test('the audit timeline is newest first paginated filtered and account scoped', function () {
    $manager = User::factory()->create();
    $otherManager = User::factory()->create();
    $account = $manager->currentAccount;

    foreach (range(1, 26) as $minute) {
        AiOperationAudit::factory()->create([
            'account_id' => $account->id,
            'actor_user_id' => $manager->id,
            'event' => $minute === 26 ? AiAuditEvent::Failed : AiAuditEvent::Succeeded,
            'purpose' => AiModelPurpose::ShortText,
            'model' => 'vendor/model-'.$minute,
            'occurred_at' => now()->subMinutes(26 - $minute),
        ]);
    }

    AiOperationAudit::factory()->create([
        'account_id' => $otherManager->current_account_id,
        'actor_user_id' => $otherManager->id,
        'model' => 'other/tenant-secret-model',
    ]);

    Livewire::actingAs($manager)->test(AiTrust::class)
        ->assertViewHas('audits', fn ($audits): bool => $audits->count() === 25
            && $audits->first()->model === 'vendor/model-26'
            && $audits->last()->model === 'vendor/model-2')
        ->set('outcome', 'failed')
        ->assertViewHas('audits', fn ($audits): bool => $audits->total() === 1
            && $audits->first()->model === 'vendor/model-26')
        ->assertDontSee('other/tenant-secret-model');
});

test('CSV export uses the filtered snapshot neutralizes formulas and audits itself outside the snapshot', function () {
    $manager = User::factory()->create();
    $operationId = (string) Str::uuid7();
    AiOperationAudit::factory()->create([
        'operation_id' => $operationId,
        'account_id' => $manager->current_account_id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Succeeded,
        'provider' => 'openrouter',
        'model' => '=HYPERLINK("https://invalid.test")',
        'cost_usd' => 0.123456,
    ]);

    $response = $this->actingAs($manager)->get(route('ai.trust.export', ['outcome' => 'succeeded']))
        ->assertOk()
        ->assertDownload();
    $exportAudit = AiOperationAudit::query()->where('event', AiAuditEvent::AuditExported->value)->sole();
    $csv = $response->streamedContent();

    expect($csv)->toContain('timestamp_utc,event,outcome,actor,purpose,provider,model,safe_fingerprint,actual_cost_usd,reserved_cost_usd,operation_id')
        ->and($csv)->toContain($operationId)
        ->and($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain($exportAudit->operation_id)
        ->and($csv)->not->toContain('request_hash')
        ->and(AiOperationAudit::query()->where('event', AiAuditEvent::AuditExported->value)->count())->toBe(1);
});

test('invalid export filters are rejected without creating an export audit', function () {
    $manager = User::factory()->create();

    $this->actingAs($manager)
        ->get(route('ai.trust.export', ['operation_id' => 'not-a-uuid']))
        ->assertSessionHasErrors('operation_id');

    expect(AiOperationAudit::query()->where('event', AiAuditEvent::AuditExported->value)->exists())->toBeFalse();
});

test('usage counts only current successful actuals and genuinely outstanding reservations once', function () {
    $manager = User::factory()->create();
    $account = $manager->currentAccount;
    AiAccountSetting::factory()->for($manager)->create([
        'account_id' => $account->id,
        'monthly_usd_limit' => 10,
        'max_concurrency' => 3,
        'byok_enabled' => true,
    ]);
    AiProviderCredential::factory()->for($manager)->create(['account_id' => $account->id]);
    AiModelPreference::factory()->for($manager)->create([
        'account_id' => $account->id,
        'purpose' => AiModelPurpose::Image,
        'mode' => AiModelMode::Custom,
        'model_ids' => ['vendor/image-first', 'vendor/image-second'],
    ]);
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Succeeded,
        'cost_usd' => 2,
    ]);
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Succeeded,
        'cost_usd' => 50,
        'occurred_at' => now()->subMonth(),
    ]);
    $completedOperation = (string) Str::uuid7();
    AiOperationAudit::factory()->create([
        'operation_id' => $completedOperation,
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Started,
        'cost_usd' => 4,
    ]);
    AiOperationAudit::factory()->create([
        'operation_id' => $completedOperation,
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Succeeded,
        'cost_usd' => 0.5,
    ]);
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Started,
        'cost_usd' => 3,
    ]);
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::PreflightStarted,
        'cost_usd' => 99,
    ]);

    $readModel = app(AiTrustCenterReadModel::class);
    $usage = $readModel->usage($account);
    $routing = collect($readModel->routing($account));

    expect($usage['actual_cost'])->toBe(2.5)
        ->and($usage['reserved_cost'])->toBe(3.0)
        ->and($usage['remaining'])->toBe(4.5)
        ->and($usage['concurrency_used'])->toBe(1)
        ->and($usage['concurrency_limit'])->toBe(3)
        ->and($routing->firstWhere('purpose', 'image'))
        ->toMatchArray([
            'mode' => 'custom',
            'route' => 'byok',
            'models' => ['vendor/image-first', 'vendor/image-second'],
        ]);

    $account->entitlement()->update(['status' => AccountEntitlementStatus::Suspended]);
    $account->unsetRelation('entitlement');

    expect(collect($readModel->routing($account))->every(fn (array $route): bool => $route['route'] === 'disabled' && $route['models'] === []))->toBeTrue();
});

test('audit filters combine safely across actor purpose provider model operation and UTC dates', function () {
    $manager = User::factory()->create();
    $account = $manager->currentAccount;
    $operationId = (string) Str::uuid7();
    AiOperationAudit::factory()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Failed,
        'purpose' => AiModelPurpose::LongText,
        'provider' => 'openrouter',
        'model' => 'vendor/needle-model',
        'occurred_at' => now('UTC'),
    ]);
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'event' => AiAuditEvent::Succeeded,
        'purpose' => AiModelPurpose::ShortText,
        'provider' => 'hosted',
        'model' => 'vendor/other-model',
    ]);

    $audits = app(AiTrustCenterReadModel::class)->timeline($account, [
        'event' => AiAuditEvent::Failed->value,
        'outcome' => 'failed',
        'actor' => $manager->id,
        'purpose' => AiModelPurpose::LongText->value,
        'provider' => 'openrouter',
        'model' => 'needle',
        'operation_id' => $operationId,
        'date_from' => now('UTC')->format('Y-m-d'),
        'date_to' => now('UTC')->format('Y-m-d'),
    ]);

    expect($audits->total())->toBe(1)
        ->and($audits->first()->operation_id)->toBe($operationId);
});

test('members cannot export another account audit and exports are throttled per manager and account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($member, ['role' => AccountRole::Member]);
    $member->forceFill(['current_account_id' => $account->id])->save();

    $this->actingAs($member)->get(route('ai.trust.export'))->assertForbidden();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($owner)->get(route('ai.trust.export'))->assertOk();
    }

    $this->actingAs($owner)->get(route('ai.trust.export'))->assertTooManyRequests();
});

test('CSV export keysets through more than two bounded chunks in exact timeline order', function () {
    config()->set('account-ai.trust_center.audit_export_chunk_size', 2);
    $manager = User::factory()->create();
    $account = $manager->currentAccount;
    $created = collect();
    $hours = [10, 8, 10, 12, 10, 7, 12];

    foreach ($hours as $index => $hour) {
        $created->push(AiOperationAudit::factory()->create([
            'operation_id' => (string) Str::uuid7(),
            'account_id' => $account->id,
            'actor_user_id' => $manager->id,
            'model' => 'vendor/chunk-'.$index,
            'occurred_at' => now('UTC')->startOfDay()->setHour($hour),
        ]));
    }

    $expectedIds = [
        $created[6]->id,
        $created[3]->id,
        $created[4]->id,
        $created[2]->id,
        $created[0]->id,
        $created[1]->id,
        $created[5]->id,
    ];
    $timelineIds = app(AiTrustCenterReadModel::class)->timeline($account, [])->pluck('id')->all();
    $auditIdsByOperationId = $created->pluck('id', 'operation_id');
    $response = $this->actingAs($manager)->get(route('ai.trust.export'))->assertOk();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = [
            'sql' => str_replace(['`', '"'], '', strtolower($query->sql)),
            'bindings' => $query->bindings,
        ];
    });
    $csv = $response->streamedContent();
    $chunkQueries = collect($queries)->filter(
        fn (array $query): bool => str_starts_with($query['sql'], 'select') && str_contains($query['sql'], 'ai_operation_audits'),
    )->values();
    $exportedIds = collect(preg_split('/\r?\n/', trim($csv)))
        ->skip(1)
        ->map(fn (string $row): array => str_getcsv($row, ',', '"', ''))
        ->map(fn (array $row): int => (int) $auditIdsByOperationId->get($row[10]))
        ->values()
        ->all();

    expect($timelineIds)->toBe($expectedIds)
        ->and($exportedIds)->toBe($timelineIds)
        ->and(array_values(array_unique($exportedIds)))->toHaveCount(7)
        ->and($chunkQueries)->toHaveCount(4)
        ->and($chunkQueries->every(fn (array $query): bool => ! str_contains($query['sql'], ' offset ')))->toBeTrue()
        ->and($chunkQueries->every(fn (array $query): bool => str_contains($query['sql'], 'occurred_at desc') && str_contains($query['sql'], 'id desc')))->toBeTrue()
        ->and($chunkQueries->every(fn (array $query): bool => str_contains($query['sql'], 'id <= ?')))->toBeTrue()
        ->and($chunkQueries->slice(1)->every(fn (array $query): bool => str_contains($query['sql'], 'occurred_at < ?')
            && str_contains($query['sql'], 'occurred_at = ?')
            && str_contains($query['sql'], 'id < ?')
            && count($query['bindings']) >= 5))->toBeTrue();
});

test('CSV export strips all cell controls before neutralizing formulas and keeps one physical row per audit', function () {
    $manager = User::factory()->create();
    $manager->forceFill(['name' => "\r\n=ACTOR\tX\x7F"])->save();
    AiOperationAudit::factory()->create([
        'operation_id' => "\x01-FORMULA-OP",
        'account_id' => $manager->current_account_id,
        'actor_user_id' => $manager->id,
        'provider' => "\n@PROVIDER\r",
        'model' => "\0+MODEL\nNEXT\x02\xC3",
    ]);

    $csv = $this->actingAs($manager)
        ->get(route('ai.trust.export'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain("'=ACTORX")
        ->and($csv)->toContain("'@PROVIDER")
        ->and($csv)->toContain("'+MODELNEXT")
        ->and($csv)->toContain("'-FORMULA-OP")
        ->and($csv)->not->toMatch('/[\x00-\x09\x0B-\x1F\x7F]/')
        ->and(mb_check_encoding($csv, 'UTF-8'))->toBeTrue()
        ->and(substr_count($csv, "\n"))->toBe(2);
});

test('actor options are capped to the 100 most recent distinct account actors with a batched deleted-safe lookup', function () {
    $manager = User::factory()->create();
    $account = $manager->currentAccount;
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $manager->id,
        'model' => 'vendor/old-manager-event',
    ]);
    $historicalActors = User::factory()->count(105)->create();

    foreach ($historicalActors as $historicalActor) {
        AiOperationAudit::factory()->create([
            'account_id' => $account->id,
            'actor_user_id' => $historicalActor->id,
        ]);
    }

    $deletedActorId = 9_999_999;
    AiOperationAudit::factory()->create([
        'account_id' => $account->id,
        'actor_user_id' => $deletedActorId,
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'select')) {
            $queries[] = $query->sql;
        }
    });

    $readModel = app(AiTrustCenterReadModel::class);
    $actors = collect($readModel->actors($account));
    $actorQueryCount = count($queries);
    $managerAudits = $readModel->timeline($account, ['actor' => $manager->id]);

    expect($actors)->toHaveCount(100)
        ->and($actors->first())->toBe(['id' => $deletedActorId, 'name' => null])
        ->and($actors->pluck('id'))->not->toContain($manager->id)
        ->and($actors->get(1)['id'])->toBe($historicalActors->last()->id)
        ->and($actorQueryCount)->toBe(2)
        ->and($managerAudits->total())->toBe(1)
        ->and($managerAudits->first()->model)->toBe('vendor/old-manager-event');
});
