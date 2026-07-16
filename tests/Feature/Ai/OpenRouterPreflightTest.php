<?php

use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Livewire\Settings\AiTrust;
use App\Models\AiModelPreference;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Ai\ByokHttpFactory;
use App\Services\Ai\OpenRouterPreflight;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

beforeEach(function () {
    config()->set('account-ai.openrouter_preflight.retry_delays_ms', [0, 0]);
    config()->set('account-ai.auto_models', [
        'short_text' => ['vendor/text-model'],
        'long_text' => ['vendor/text-model'],
        'image_prompt' => ['vendor/text-model'],
        'image' => ['vendor/image-model'],
        'auto' => ['vendor/text-model'],
    ]);
});

test('preflight validates the encrypted key and configured modalities using only isolated GET requests', function () {
    $manager = User::factory()->create();
    $credential = AiProviderCredential::factory()->for($manager)->create([
        'secret' => 'sk-or-v1-preflight-secret',
        'fingerprint' => str_repeat('a', 64),
    ]);
    Event::fake([RequestSending::class, ResponseReceived::class]);
    Http::preventStrayRequests();
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fake([
        'openrouter.ai/api/v1/key' => Http::response([
            'data' => [
                'label' => '<b>=Production key</b>',
                'usage' => 987.65,
            ],
        ]),
        'openrouter.ai/api/v1/models?output_modalities=all' => Http::response([
            'data' => [
                ['id' => 'vendor/text-model', 'architecture' => ['output_modalities' => ['text']]],
                ['id' => 'vendor/image-model', 'architecture' => ['output_modalities' => ['image']]],
            ],
        ]),
    ]);

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);
    $audits = AiOperationAudit::query()->orderBy('id')->get();
    $recorded = collect($http->recorded());
    $rawAudits = json_encode(DB::table('ai_operation_audits')->get(), JSON_THROW_ON_ERROR);

    expect($result->status)->toBe('succeeded')
        ->and($result->keyValid)->toBeTrue()
        ->and($result->label)->toBe('=Production key')
        ->and($result->models)->toHaveCount(5)
        ->and(collect($result->models)->every(fn (array $model): bool => $model['exists'] && $model['compatible']))->toBeTrue()
        ->and($recorded)->toHaveCount(2)
        ->and($recorded->map(fn (array $entry): string => $entry[0]->method())->all())->toBe(['GET', 'GET'])
        ->and($recorded->map(fn (array $entry): string => $entry[0]->url())->all())->toBe([
            'https://openrouter.ai/api/v1/key',
            'https://openrouter.ai/api/v1/models?output_modalities=all',
        ])
        ->and($recorded->every(fn (array $entry): bool => $entry[0]->hasHeader('Authorization', 'Bearer sk-or-v1-preflight-secret')))->toBeTrue()
        ->and($audits->pluck('event')->all())->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightSucceeded])
        ->and($audits->pluck('operation_id')->unique()->count())->toBe(1)
        ->and($audits->last()->credential_fingerprint)->toBe($credential->fingerprint)
        ->and($rawAudits)->not->toContain('sk-or-v1-preflight-secret')
        ->and($rawAudits)->not->toContain('Production key')
        ->and($rawAudits)->not->toContain('987.65');
    Event::assertNotDispatched(RequestSending::class);
    Event::assertNotDispatched(ResponseReceived::class);
});

test('invalid keys fail safely without requesting models or persisting a response', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create(['secret' => 'sk-or-v1-invalid-secret']);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fake(['openrouter.ai/api/v1/key' => Http::response(['error' => 'no'], 401)]);

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($result->status)->toBe('failed')
        ->and($result->keyValid)->toBeFalse()
        ->and($http->recorded())->toHaveCount(1)
        ->and(AiOperationAudit::query()->orderBy('id')->pluck('event')->all())
        ->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightFailed])
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('invalid_key');
});

test('missing or incompatible models return a recoverable key-valid failure', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create();
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fake([
        'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'Team key']]),
        'openrouter.ai/api/v1/models?output_modalities=all' => Http::response(['data' => [
            ['id' => 'vendor/text-model', 'architecture' => ['output_modalities' => ['text']]],
            ['id' => 'vendor/image-model', 'architecture' => ['output_modalities' => ['text']]],
        ]]),
    ]);

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);
    $imageResult = collect($result->models)->firstWhere('purpose', 'image');

    expect($result->status)->toBe('failed')
        ->and($result->keyValid)->toBeTrue()
        ->and($imageResult['exists'])->toBeTrue()
        ->and($imageResult['compatible'])->toBeFalse()
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('configuration_invalid');
});

test('revoked credentials and throttled attempts are prevented without a provider call', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create(['revoked_at' => now()]);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();

    $revoked = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($revoked->status)->toBe('prevented')
        ->and($http->recorded())->toHaveCount(0)
        ->and(AiOperationAudit::query()->orderBy('id')->pluck('event')->all())
        ->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightPrevented]);

    AiProviderCredential::query()->update(['revoked_at' => null]);
    $rateKey = 'openrouter-preflight|'.$manager->current_account_id.'|'.$manager->id;
    RateLimiter::hit($rateKey, 3600);
    RateLimiter::hit($rateKey, 3600);
    RateLimiter::hit($rateKey, 3600);
    RateLimiter::hit($rateKey, 3600);
    RateLimiter::hit($rateKey, 3600);

    $throttled = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($throttled->status)->toBe('prevented')
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('rate_limited')
        ->and($http->recorded())->toHaveCount(0);
});

test('preflight retries transient server failures and rejects malformed bounded responses', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create();
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fakeSequence()
        ->push(['error' => 'retry'], 500)
        ->push(['data' => ['label' => 'Recovered']])
        ->push('not-json', 200);

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($http->recorded())->toHaveCount(3)
        ->and($result->status)->toBe('failed')
        ->and($result->keyValid)->toBeNull()
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('invalid_json');
});

test('preflight bounds response bytes and refuses a configured endpoint outside the HTTPS allowlist', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create();
    config()->set('account-ai.openrouter_preflight.max_response_bytes', 16);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fake(['openrouter.ai/api/v1/key' => Http::response(str_repeat('x', 17))]);

    $oversized = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($oversized->status)->toBe('failed')
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('response_too_large');

    config()->set('account-ai.openrouter_preflight.max_response_bytes', 2_000_000);
    config()->set('account-ai.openrouter_preflight.base_url', 'http://attacker.invalid/api/v1');
    RateLimiter::clear('openrouter-preflight|'.$manager->current_account_id.'|'.$manager->id);
    $unsafe = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($unsafe->status)->toBe('failed')
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('unsafe_endpoint')
        ->and($http->recorded())->toHaveCount(1);
});

test('preflight retries failed connections only to the strict attempt bound', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create();
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fake(['openrouter.ai/api/v1/key' => ByokHttpFactory::failedConnection()]);

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($result->status)->toBe('failed')
        ->and($result->keyValid)->toBeNull()
        ->and($http->recorded())->toHaveCount(3)
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('provider_unavailable');
});

test('preflight rejects every noncanonical OpenRouter base URL before HTTP', function (string $baseUrl) {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create();
    config()->set('account-ai.openrouter_preflight.base_url', $baseUrl);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($result->status)->toBe('failed')
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('unsafe_endpoint')
        ->and($http->recorded())->toHaveCount(0);
})->with([
    'wrong path' => 'https://openrouter.ai/api/v2',
    'trailing slash' => 'https://openrouter.ai/api/v1/',
    'double slash' => 'https://openrouter.ai/api//v1',
    'encoded slash' => 'https://openrouter.ai/api/v1%2Fkey/..',
    'encoded dot segment' => 'https://openrouter.ai/api/%2e%2e/v1',
    'query' => 'https://openrouter.ai/api/v1?target=other',
    'fragment' => 'https://openrouter.ai/api/v1#other',
    'userinfo' => 'https://user@openrouter.ai/api/v1',
    'explicit standard port' => 'https://openrouter.ai:443/api/v1',
    'zero-padded standard port' => 'https://openrouter.ai:0443/api/v1',
    'empty port' => 'https://openrouter.ai:/api/v1',
    'non-443 port' => 'https://openrouter.ai:444/api/v1',
    'uppercase scheme' => 'HTTPS://openrouter.ai/api/v1',
    'uppercase host' => 'https://OpenRouter.ai/api/v1',
    'uppercase path' => 'https://openrouter.ai/API/v1',
    'dot segment' => 'https://openrouter.ai/api/./v1',
    'backslash' => 'https://openrouter.ai/api\\v1',
    'leading control' => "\thttps://openrouter.ai/api/v1",
    'trailing control' => "https://openrouter.ai/api/v1\n",
    'other host' => 'https://api.openrouter.ai/api/v1',
]);

test('unsafe endpoint is audited before a corrupted credential can be decrypted', function () {
    $manager = User::factory()->create();
    $credential = AiProviderCredential::factory()->for($manager)->create();
    DB::table($credential->getTable())
        ->where('id', $credential->id)
        ->update(['secret' => 'deliberately-invalid-encrypted-payload']);
    config()->set('account-ai.openrouter_preflight.base_url', 'https://openrouter.ai:443/api/v1');
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);
    $audits = AiOperationAudit::query()->orderBy('id')->get();

    expect($result->status)->toBe('failed')
        ->and($result->keyValid)->toBeNull()
        ->and($result->label)->toBeNull()
        ->and($result->models)->toBe([])
        ->and($http->recorded())->toHaveCount(0)
        ->and($audits)->toHaveCount(2)
        ->and($audits->pluck('event')->all())->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightFailed])
        ->and($audits->pluck('operation_id')->unique())->toHaveCount(1)
        ->and($audits->last()->error_code)->toBe('unsafe_endpoint');
});

test('corrupted credential ciphertext fails with a sanitized terminal audit on the canonical endpoint', function () {
    $manager = User::factory()->create();
    $credential = AiProviderCredential::factory()->for($manager)->create();
    $corruptedPayload = 'deliberately-invalid-encrypted-payload';
    DB::table($credential->getTable())
        ->where('id', $credential->id)
        ->update(['secret' => $corruptedPayload]);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);
    $audits = AiOperationAudit::query()->orderBy('id')->get();
    $rawAudits = json_encode(DB::table((new AiOperationAudit)->getTable())->get(), JSON_THROW_ON_ERROR);

    expect($result->status)->toBe('failed')
        ->and($result->keyValid)->toBeNull()
        ->and($result->label)->toBeNull()
        ->and($result->models)->toBe([])
        ->and($http->recorded())->toHaveCount(0)
        ->and($audits)->toHaveCount(2)
        ->and($audits->pluck('event')->all())->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightFailed])
        ->and($audits->pluck('operation_id')->unique())->toHaveCount(1)
        ->and($audits->last()->error_code)->toBe('provider_unavailable')
        ->and($rawAudits)->not->toContain($corruptedPayload)
        ->and($rawAudits)->not->toContain('DecryptException');
});

test('preflight suppresses provider results when terminal account state no longer matches admission', function (string $stateChange) {
    $manager = User::factory()->create();
    $account = $manager->currentAccount;
    $credential = AiProviderCredential::factory()->for($manager)->create([
        'secret' => 'sk-or-v1-original-secret',
        'fingerprint' => str_repeat('a', 64),
    ]);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();
    $http->fake([
        'openrouter.ai/api/v1/key' => Http::response(['data' => ['label' => 'must-be-suppressed']]),
        'openrouter.ai/api/v1/models?output_modalities=all' => function () use ($stateChange, $manager, $account, $credential) {
            match ($stateChange) {
                'credential_revoked' => $credential->update(['revoked_at' => now()]),
                'credential_rotated' => $credential->update([
                    'secret' => 'sk-or-v1-rotated-secret',
                    'fingerprint' => str_repeat('b', 64),
                    'rotated_at' => now(),
                ]),
                'configuration_changed' => AiModelPreference::factory()->for($manager)->create([
                    'account_id' => $account->id,
                    'purpose' => AiModelPurpose::ShortText,
                    'mode' => AiModelMode::Custom,
                    'model_ids' => ['vendor/reconfigured-model'],
                ]),
                'manager_demoted' => DB::table('account_user')
                    ->where('account_id', $account->id)
                    ->where('user_id', $manager->id)
                    ->update(['role' => AccountRole::Member->value]),
                'capability_suspended' => $account->entitlement()->update(['status' => AccountEntitlementStatus::Suspended]),
                'account_erasure' => DB::table('accounts')->where('id', $account->id)->update(['erasure_started_at' => now()]),
                'user_erasure' => DB::table('users')->where('id', $manager->id)->update(['account_erasure_started_at' => now()]),
                'actor_deleted' => (function () use ($manager, $account): void {
                    DB::table('account_user')
                        ->where('account_id', $account->id)
                        ->where('user_id', $manager->id)
                        ->delete();
                    DB::table('users')->where('id', $manager->id)->delete();
                })(),
                'account_deleted' => DB::table('accounts')->where('id', $account->id)->delete(),
            };

            return Http::response(['data' => [
                ['id' => 'vendor/text-model', 'architecture' => ['output_modalities' => ['text']]],
                ['id' => 'vendor/image-model', 'architecture' => ['output_modalities' => ['image']]],
            ]]);
        },
    ]);

    $result = app(OpenRouterPreflight::class)->run($manager, $account);
    $audits = AiOperationAudit::query()->where('operation_id', $result->operationId)->orderBy('id')->get();

    expect($result->status)->toBe('prevented')
        ->and($result->keyValid)->toBeNull()
        ->and($result->label)->toBeNull()
        ->and($result->models)->toBe([])
        ->and($audits)->toHaveCount(2)
        ->and($audits->pluck('event')->all())->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightPrevented])
        ->and($audits->last()->error_code)->toBe('state_changed')
        ->and($audits->last()->credential_fingerprint)->toBe(str_repeat('a', 64))
        ->and($audits->last()->output_hash)->toBeNull()
        ->and($http->recorded())->toHaveCount(2);
})->with([
    'credential revoked' => 'credential_revoked',
    'credential rotated' => 'credential_rotated',
    'routing changed' => 'configuration_changed',
    'manager demoted' => 'manager_demoted',
    'capability suspended' => 'capability_suspended',
    'account erasure' => 'account_erasure',
    'user erasure' => 'user_erasure',
    'actor deleted' => 'actor_deleted',
    'account deleted' => 'account_deleted',
]);

test('preflight requires fresh manager reauthentication before contacting the provider', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create();
    app(ByokHttpFactory::class)->preventStrayRequests();
    session()->put('auth.password_confirmed_at', 0);

    Livewire::actingAs($manager)->test(AiTrust::class)
        ->call('runPreflight')
        ->assertHasErrors('preflight');

    expect(AiOperationAudit::query()->exists())->toBeFalse()
        ->and(app(ByokHttpFactory::class)->recorded())->toHaveCount(0);
});

test('cross-account managers and accounts pending erasure fail closed before preflight auditing or HTTP', function () {
    $manager = User::factory()->create();
    $otherManager = User::factory()->create();
    AiProviderCredential::factory()->for($otherManager)->create();
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();

    expect(fn () => app(OpenRouterPreflight::class)->run($manager, $otherManager->currentAccount))
        ->toThrow(AuthorizationException::class);

    $manager->currentAccount->forceFill(['erasure_started_at' => now()])->save();

    expect(fn () => app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount))
        ->toThrow(GoneHttpException::class)
        ->and($http->recorded())->toHaveCount(0)
        ->and(AiOperationAudit::query()->exists())->toBeFalse();
});

test('suspended AI capability produces a started and prevented audit without decrypting or calling the key', function () {
    $manager = User::factory()->create();
    AiProviderCredential::factory()->for($manager)->create(['secret' => 'sk-or-v1-never-used']);
    $manager->currentAccount->entitlement()->update(['status' => AccountEntitlementStatus::Suspended]);
    $http = app(ByokHttpFactory::class);
    $http->preventStrayRequests();

    $result = app(OpenRouterPreflight::class)->run($manager, $manager->currentAccount);

    expect($result->status)->toBe('prevented')
        ->and($http->recorded())->toHaveCount(0)
        ->and(AiOperationAudit::query()->orderBy('id')->pluck('event')->all())
        ->toBe([AiAuditEvent::PreflightStarted, AiAuditEvent::PreflightPrevented])
        ->and(AiOperationAudit::query()->latest('id')->value('error_code'))->toBe('capability_unavailable');
});
