<?php

use App\Enums\AiAuditEvent;
use App\Enums\AiModelPurpose;
use App\Exceptions\PaidAiUnavailable;
use App\Models\AiAccountSetting;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AuditedAiExecutor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

test('a BYOK operation is started and completed without storing prompt or output', function () {
    $user = User::factory()->create();
    AiAccountSetting::factory()->for($user)->create(['byok_enabled' => true]);
    $credential = AiProviderCredential::factory()->for($user)->create(['fingerprint' => str_repeat('a', 64)]);

    $called = false;
    $result = app(AuditedAiExecutor::class)->execute(
        $user,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'private prompt',
        function (AiExecutionContext $context) use (&$called, $credential): string {
            $called = true;
            expect($context->credential?->is($credential))->toBeTrue();

            return 'private output';
        },
        fn (string $output): string => $output,
    );

    $rows = AiOperationAudit::query()->where('account_id', $user->id)->orderBy('id')->get();

    expect($called)->toBeTrue()
        ->and($result)->toBe('private output')
        ->and($rows->pluck('event')->all())->toBe([AiAuditEvent::Started, AiAuditEvent::Succeeded])
        ->and(json_encode($rows->toArray()))->not->toContain('private prompt')->not->toContain('private output');
});

test('the account gate prevents provider execution when paid AI is disabled', function () {
    $user = User::factory()->create();
    AiAccountSetting::factory()->for($user)->create(['paid_ai_enabled' => false]);
    $called = false;

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $user,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'prompt',
        function () use (&$called): string {
            $called = true;

            return 'never';
        },
    ))->toThrow(PaidAiUnavailable::class);

    expect($called)->toBeFalse()
        ->and(AiOperationAudit::query()->where('account_id', $user->id)->value('event'))->toBe(AiAuditEvent::Prevented);
});

test('configured spending limits fail closed on conservative estimates and active reservations', function () {
    $user = User::factory()->create();
    AiAccountSetting::factory()->for($user)->create([
        'per_operation_usd_limit' => 0.01,
        'monthly_usd_limit' => 0.15,
        'max_concurrency' => 2,
    ]);
    $called = false;

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $user,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'prompt',
        function () use (&$called): string {
            $called = true;

            return 'never';
        },
    ))->toThrow(PaidAiUnavailable::class);

    expect($called)->toBeFalse();

    $user->aiAccountSetting()->update(['per_operation_usd_limit' => null]);
    AiOperationAudit::query()->create([
        'operation_id' => fake()->uuid(),
        'account_id' => $user->id,
        'event' => AiAuditEvent::Started,
        'purpose' => AiModelPurpose::ShortText,
        'cost_usd' => 0.10,
        'occurred_at' => now(),
    ]);

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $user,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'another prompt',
        fn (): string => 'never',
    ))->toThrow(PaidAiUnavailable::class);
});

test('an audit insertion failure prevents provider execution', function () {
    $user = User::factory()->create();
    $called = false;
    $event = 'eloquent.creating: '.AiOperationAudit::class;
    Event::listen($event, fn () => throw new RuntimeException('audit unavailable'));

    try {
        expect(fn () => app(AuditedAiExecutor::class)->execute(
            $user,
            AiModelPurpose::ShortText,
            'openrouter',
            'openrouter/auto',
            'prompt',
            function () use (&$called): string {
                $called = true;

                return 'never';
            },
        ))->toThrow(PaidAiUnavailable::class, 'securely audited');
    } finally {
        Event::forget($event);
    }

    expect($called)->toBeFalse();
});

test('credentials never cross account boundaries', function () {
    $credentialOwner = User::factory()->create();
    $otherUser = User::factory()->create();
    AiAccountSetting::factory()->for($credentialOwner)->create(['byok_enabled' => true]);
    AiProviderCredential::factory()->for($credentialOwner)->create();
    $credentialSeen = true;

    app(AuditedAiExecutor::class)->execute(
        $otherUser,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'prompt',
        function (AiExecutionContext $context) use (&$credentialSeen): string {
            $credentialSeen = $context->credential !== null;

            return 'safe';
        },
    );

    expect($credentialSeen)->toBeFalse();
});

test('AI audit rows are immutable through Eloquent and raw SQL', function () {
    $audit = AiOperationAudit::query()->create([
        'operation_id' => fake()->uuid(),
        'account_id' => 99,
        'event' => AiAuditEvent::Started,
        'occurred_at' => now(),
    ]);

    expect(fn () => $audit->update(['error_code' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => DB::table('ai_operation_audits')->where('id', $audit->id)->update(['error_code' => 'changed']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('ai_operation_audits')->where('id', $audit->id)->delete())->toThrow(QueryException::class);
});

test('account erasure removes AI secrets and controls but retains the permanent audit ledger', function () {
    $user = User::factory()->create();
    AiAccountSetting::factory()->for($user)->create();
    AiProviderCredential::factory()->for($user)->create();
    $audit = AiOperationAudit::query()->create([
        'operation_id' => fake()->uuid(),
        'account_id' => $user->id,
        'actor_user_id' => $user->id,
        'event' => AiAuditEvent::CredentialSaved,
        'occurred_at' => now(),
    ]);

    $user->currentAccount()->sole()->delete();
    $user->delete();

    expect(AiAccountSetting::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(AiProviderCredential::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(AiOperationAudit::query()->whereKey($audit)->exists())->toBeTrue();
});
