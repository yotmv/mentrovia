<?php

use App\Enums\AccountEntitlementStatus;
use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Exceptions\PaidAiUnavailable;
use App\Livewire\Settings\Ai;
use App\Models\Account;
use App\Models\AiAccountSetting;
use App\Models\AiModelPreference;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Ai\AiAccountGate;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AuditedAiExecutor;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function joinAiAccount(User $user, Account $account, AccountRole $role): void
{
    $account->members()->attach($user, ['role' => $role->value]);
    $user->forceFill(['current_account_id' => $account->id])->save();
    $user->refresh();
}

test('account members share policy credentials models and audit attribution', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $member = User::factory()->create();
    joinAiAccount($member, $account, AccountRole::Member);

    AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true]);
    $credential = AiProviderCredential::factory()->for($owner)->create([
        'fingerprint' => str_repeat('a', 64),
    ]);
    AiModelPreference::factory()->for($owner)->create([
        'purpose' => AiModelPurpose::ShortText,
        'mode' => AiModelMode::Custom,
        'model_ids' => ['openai/gpt-4.1-mini'],
    ]);

    $result = app(AuditedAiExecutor::class)->execute(
        $member,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'private account prompt',
        function (AiExecutionContext $context) use ($account, $credential): string {
            expect($context->accountId)->toBe($account->id)
                ->and($context->model)->toBe('openai/gpt-4.1-mini')
                ->and($context->credential?->is($credential))->toBeTrue();

            return 'shared result';
        },
        outputText: fn (string $output): string => $output,
    );

    $audits = AiOperationAudit::query()->where('actor_user_id', $member->id)->orderBy('id')->get();

    expect($result)->toBe('shared result')
        ->and($audits)->toHaveCount(2)
        ->and($audits->pluck('account_id')->unique()->values()->all())->toBe([$account->id])
        ->and($audits->pluck('event')->all())->toBe([AiAuditEvent::Started, AiAuditEvent::Succeeded]);
});

test('members share the account monthly budget ledger', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $member = User::factory()->create();
    joinAiAccount($member, $account, AccountRole::Member);
    AiAccountSetting::factory()->for($owner)->create(['monthly_usd_limit' => 0.15]);
    AiOperationAudit::query()->create([
        'operation_id' => fake()->uuid(),
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Succeeded,
        'purpose' => AiModelPurpose::ShortText,
        'cost_usd' => 0.10,
        'occurred_at' => now(),
    ]);

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $member,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'over shared budget',
        fn (): string => 'never',
        hostedEstimatedCostUsd: 0.06,
    ))->toThrow(PaidAiUnavailable::class);

    expect(AiOperationAudit::query()
        ->where('account_id', $account->id)
        ->where('actor_user_id', $member->id)
        ->value('event'))->toBe(AiAuditEvent::Prevented);
});

test('members share the account concurrency ledger', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $member = User::factory()->create();
    joinAiAccount($member, $account, AccountRole::Member);
    AiAccountSetting::factory()->for($owner)->create(['max_concurrency' => 1]);
    $operationId = fake()->uuid();
    AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Started,
        'purpose' => AiModelPurpose::ShortText,
        'cost_usd' => 0.01,
        'occurred_at' => now(),
    ]);
    $otherAccount = User::factory()->create()->currentAccount()->sole();
    AiOperationAudit::query()->create([
        'operation_id' => $operationId,
        'account_id' => $otherAccount->id,
        'event' => AiAuditEvent::Failed,
        'purpose' => AiModelPurpose::ShortText,
        'occurred_at' => now(),
    ]);

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $member,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'over shared concurrency',
        fn (): string => 'never',
        hostedEstimatedCostUsd: 0.01,
    ))->toThrow(PaidAiUnavailable::class);

    expect(AiOperationAudit::query()
        ->where('account_id', $account->id)
        ->where('actor_user_id', $member->id)
        ->value('event'))->toBe(AiAuditEvent::Prevented);
});

test('AI controls and credentials never cross account boundaries', function () {
    $firstOwner = User::factory()->create();
    $firstAccount = $firstOwner->currentAccount()->sole();
    AiAccountSetting::factory()->for($firstOwner)->create(['byok_enabled' => true]);
    AiProviderCredential::factory()->for($firstOwner)->create();

    $secondOwner = User::factory()->create();
    $secondAccount = $secondOwner->currentAccount()->sole();
    $credentialSeen = true;

    app(AuditedAiExecutor::class)->execute(
        $secondOwner,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'account B prompt',
        function (AiExecutionContext $context) use ($secondAccount, &$credentialSeen): string {
            $credentialSeen = $context->credential !== null;
            expect($context->accountId)->toBe($secondAccount->id);

            return 'isolated';
        },
    );

    expect($credentialSeen)->toBeFalse()
        ->and(AiOperationAudit::query()->where('actor_user_id', $secondOwner->id)->pluck('account_id')->unique()->all())
        ->toBe([$secondAccount->id])
        ->and(AiOperationAudit::query()->where('account_id', $firstAccount->id)->exists())->toBeFalse();
});

test('explicit origin account remains stable after the actor switches current accounts', function () {
    $originOwner = User::factory()->create();
    $originAccount = $originOwner->currentAccount()->sole();
    AiAccountSetting::factory()->for($originOwner)->create(['byok_enabled' => true]);
    $credential = AiProviderCredential::factory()->for($originOwner)->create();

    $actor = User::factory()->create();
    $actorAccount = $actor->currentAccount()->sole();
    $originAccount->members()->attach($actor, ['role' => AccountRole::Member->value]);

    $result = app(AuditedAiExecutor::class)->execute(
        $actor,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'captured origin request',
        function (AiExecutionContext $context) use ($credential, $originAccount): string {
            expect($context->accountId)->toBe($originAccount->id)
                ->and($context->credential?->is($credential))->toBeTrue();

            return 'origin result';
        },
        account: $originAccount->id,
    );

    expect($actor->current_account_id)->toBe($actorAccount->id)
        ->and($result)->toBe('origin result')
        ->and(AiOperationAudit::query()->where('actor_user_id', $actor->id)->pluck('account_id')->unique()->all())
        ->toBe([$originAccount->id]);
});

test('explicit origin account denies execution after actor membership is removed', function () {
    $originOwner = User::factory()->create();
    $originAccount = $originOwner->currentAccount()->sole();
    AiAccountSetting::factory()->for($originOwner)->create(['byok_enabled' => true]);
    AiProviderCredential::factory()->for($originOwner)->create();
    $actor = User::factory()->create();
    $originAccount->members()->attach($actor, ['role' => AccountRole::Member->value]);
    $originAccount->members()->detach($actor);
    $providerCalled = false;

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $actor,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'revoked origin request',
        function () use (&$providerCalled): string {
            $providerCalled = true;

            return 'never';
        },
        account: $originAccount->id,
    ))->toThrow(PaidAiUnavailable::class);

    $prevented = AiOperationAudit::query()->where('actor_user_id', $actor->id)->sole();

    expect($providerCalled)->toBeFalse()
        ->and($prevented->event)->toBe(AiAuditEvent::Prevented)
        ->and($prevented->account_id)->toBe($originAccount->id);
});

test('non-entitled accounts fail closed before implicit or explicit paid AI routing', function (
    string $plan,
    AccountEntitlementStatus $status,
    bool $expiredTrial,
    bool $explicitOrigin,
) {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->entitlement()->sole()->update([
        'plan' => $plan,
        'status' => $status,
        'trial_ends_at' => $expiredTrial ? now()->subSecond() : null,
    ]);
    $account->unsetRelation('entitlement');

    if ($explicitOrigin) {
        AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true]);
        AiProviderCredential::factory()->for($owner)->create();
        $actor = User::factory()->create();
        $account->members()->attach($actor, ['role' => AccountRole::Member->value]);
        $accountArgument = $account->id;
    } else {
        $actor = $owner;
        $accountArgument = null;
    }

    $providerCalled = false;

    expect(fn () => app(AuditedAiExecutor::class)->execute(
        $actor,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'non-entitled request',
        function () use (&$providerCalled): string {
            $providerCalled = true;

            return 'never';
        },
        account: $accountArgument,
    ))->toThrow(PaidAiUnavailable::class, 'account entitlement');

    $prevented = AiOperationAudit::query()->where('actor_user_id', $actor->id)->sole();

    expect($providerCalled)->toBeFalse()
        ->and($prevented->event)->toBe(AiAuditEvent::Prevented)
        ->and($prevented->account_id)->toBe($account->id);

    if (! $explicitOrigin) {
        expect($account->aiAccountSetting()->exists())->toBeFalse();
    }
})->with([
    'implicit unknown plan' => ['unknown-plan', AccountEntitlementStatus::Active, false, false],
    'explicit unknown plan BYOK' => ['unknown-plan', AccountEntitlementStatus::Active, false, true],
    'implicit suspended' => ['beta', AccountEntitlementStatus::Suspended, false, false],
    'explicit suspended BYOK' => ['beta', AccountEntitlementStatus::Suspended, false, true],
    'implicit canceled' => ['beta', AccountEntitlementStatus::Canceled, false, false],
    'explicit canceled BYOK' => ['beta', AccountEntitlementStatus::Canceled, false, true],
    'implicit expired trial' => ['beta', AccountEntitlementStatus::Trialing, true, false],
    'explicit expired trial BYOK' => ['beta', AccountEntitlementStatus::Trialing, true, true],
]);

test('BYOK model discovery stops at the entitlement boundary without reading account AI controls', function (
    string $plan,
    AccountEntitlementStatus $status,
    bool $expiredTrial,
    bool $explicitOrigin,
) {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $account->entitlement()->sole()->update([
        'plan' => $plan,
        'status' => $status,
        'trial_ends_at' => $expiredTrial ? now()->subSecond() : null,
    ]);
    $account->unsetRelation('entitlement');

    if ($explicitOrigin) {
        $actor = User::factory()->create();
        $account->members()->attach($actor, ['role' => AccountRole::Member->value]);
        $accountArgument = $account->id;
    } else {
        $actor = $owner;
        $accountArgument = null;
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $models = app(AiAccountGate::class)->activeByokModels(
        $actor,
        AiModelPurpose::ShortText,
        $accountArgument,
    );
    $queries = str((string) collect(DB::getQueryLog())->pluck('query')->implode(' '))->lower()->toString();
    DB::disableQueryLog();

    expect($models)->toBeNull()
        ->and($queries)->not->toContain('ai_account_settings')
        ->and($queries)->not->toContain('ai_provider_credentials')
        ->and($queries)->not->toContain('ai_model_preferences')
        ->and(AiAccountSetting::query()->exists())->toBeFalse()
        ->and(AiProviderCredential::query()->exists())->toBeFalse()
        ->and(AiModelPreference::query()->exists())->toBeFalse();
})->with([
    'implicit unknown plan' => ['unknown-plan', AccountEntitlementStatus::Active, false, false],
    'explicit unknown plan' => ['unknown-plan', AccountEntitlementStatus::Active, false, true],
    'implicit suspended' => ['beta', AccountEntitlementStatus::Suspended, false, false],
    'explicit suspended' => ['beta', AccountEntitlementStatus::Suspended, false, true],
    'implicit canceled' => ['beta', AccountEntitlementStatus::Canceled, false, false],
    'explicit canceled' => ['beta', AccountEntitlementStatus::Canceled, false, true],
    'implicit expired trial' => ['beta', AccountEntitlementStatus::Trialing, true, false],
    'explicit expired trial' => ['beta', AccountEntitlementStatus::Trialing, true, true],
]);

test('owners may manage account AI controls', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)->get(route('ai.edit'))->assertOk();

    Livewire::actingAs($owner)->test(Ai::class)
        ->set('maxConcurrency', 4)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($owner->currentAccount()->sole()->aiAccountSetting?->max_concurrency)->toBe(4);
});

test('account admins may manage account AI controls and credentials', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $admin = User::factory()->create();
    $adminAccount = $admin->currentAccount()->sole();
    AiProviderCredential::factory()->for($admin)->create();
    joinAiAccount($admin, $account, AccountRole::Admin);

    $this->actingAs($admin)->get(route('ai.edit'))->assertOk();
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('ai.credential.store'), [
            'openrouter_api_key' => 'sk-or-v1-admin-managed-secret-1234',
        ])->assertRedirect(route('ai.edit'));

    expect($account->aiProviderCredentials()->whereNull('revoked_at')->exists())->toBeTrue()
        ->and($adminAccount->aiProviderCredentials()->whereNull('revoked_at')->exists())->toBeTrue()
        ->and(AiOperationAudit::query()->where('actor_user_id', $admin->id)->value('account_id'))->toBe($account->id);
});

test('account members may use enabled AI but cannot read or manage its controls or credentials', function () {
    $owner = User::factory()->create();
    $account = $owner->currentAccount()->sole();
    $member = User::factory()->create();
    joinAiAccount($member, $account, AccountRole::Member);
    AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true]);
    AiProviderCredential::factory()->for($owner)->create([
        'secret' => 'sk-or-v1-private-workspace-key-1234',
        'last_four' => '1234',
    ]);

    $this->actingAs($member)->get(route('ai.edit'))
        ->assertForbidden()
        ->assertDontSee('1234')
        ->assertDontSee('sk-or-v1-private-workspace-key-1234');

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('ai.credential.store'), [
            'openrouter_api_key' => 'sk-or-v1-member-forbidden-secret-1234',
        ])->assertForbidden();

    expect($account->aiProviderCredentials()->count())->toBe(1)
        ->and($account->aiProviderCredentials()->sole()->secret)->toBe('sk-or-v1-private-workspace-key-1234');

    $usedCredential = false;
    app(AuditedAiExecutor::class)->execute(
        $member,
        AiModelPurpose::ShortText,
        'openrouter',
        'openrouter/auto',
        'member operation',
        function (AiExecutionContext $context) use (&$usedCredential): string {
            $usedCredential = $context->usesByok();

            return 'allowed';
        },
    );

    expect($usedCredential)->toBeTrue();
});
