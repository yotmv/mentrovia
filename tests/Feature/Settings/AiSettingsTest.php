<?php

use App\Enums\AccountRole;
use App\Enums\AiAuditEvent;
use App\Livewire\Settings\Ai;
use App\Models\AiAccountSetting;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('verified users can manage account AI controls', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('ai.edit'))->assertOk()->assertSee('AI controls');

    Livewire::actingAs($user)->test(Ai::class)
        ->set('monthlyUsdLimit', '25.50')
        ->set('perOperationUsdLimit', '1.25')
        ->set('maxConcurrency', 3)
        ->call('saveSettings')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('ai_account_settings', [
        'user_id' => $user->id,
        'monthly_usd_limit' => 25.5000,
        'max_concurrency' => 3,
    ]);
});

test('OpenRouter credentials are write only encrypted and lifecycle audited', function () {
    $user = User::factory()->create();
    $key = 'sk-or-v1-test-secret-key-material-1234';

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('ai.credential.store'), ['openrouter_api_key' => $key])
        ->assertRedirect(route('ai.edit'));

    $raw = DB::table('ai_provider_credentials')->where('user_id', $user->id)->first();

    expect($raw->secret)->not->toContain($key)
        ->and(AiProviderCredential::query()->where('user_id', $user->id)->firstOrFail()->secret)->toBe($key)
        ->and(AiOperationAudit::query()->where('account_id', $user->id)->firstOrFail()->event)->toBe(AiAuditEvent::CredentialSaved);
});

test('credential mutations require password confirmation and never flash the key', function () {
    $user = User::factory()->create();
    $key = 'short-secret';

    $this->actingAs($user)
        ->post(route('ai.credential.store'), ['openrouter_api_key' => $key])
        ->assertRedirect(route('password.confirm'));

    expect(AiProviderCredential::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('ai.edit'))
        ->post(route('ai.credential.store'), ['openrouter_api_key' => $key])
        ->assertRedirect(route('ai.edit'))
        ->assertSessionHasErrors('openrouter_api_key')
        ->assertSessionMissing('_old_input.openrouter_api_key');
});

test('custom model IDs are validated deduplicated and saved by purpose', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Ai::class)
        ->set('modelModes.short_text', 'custom')
        ->set('newModels.short_text', 'openai/gpt-4.1-mini')
        ->call('addModel', 'short_text')
        ->set('newModels.short_text', 'openai/gpt-4.1-mini')
        ->call('addModel', 'short_text')
        ->call('saveModels')
        ->assertHasNoErrors();

    $preference = $user->aiModelPreferences()->where('purpose', 'short_text')->firstOrFail();

    expect($preference->model_ids)->toBe(['openai/gpt-4.1-mini']);
});

test('BYOK cannot be enabled before a credential is saved', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Ai::class)
        ->set('byokEnabled', true)
        ->call('saveSettings')
        ->assertHasErrors('byokEnabled');
});

test('a stale settings component cannot re-enable BYOK after its credential is revoked', function () {
    $user = User::factory()->create();
    $account = $user->currentAccount;
    $settings = AiAccountSetting::factory()->for($user)->create([
        'account_id' => $account->id,
        'byok_enabled' => false,
        'hosted_ai_enabled' => true,
        'monthly_usd_limit' => 40,
        'max_concurrency' => 2,
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('ai.credential.store'), [
            'openrouter_api_key' => 'sk-or-v1-first-active-secret-123456789',
        ])
        ->assertRedirect(route('ai.edit'));

    $staleComponent = Livewire::actingAs($user)->test(Ai::class)
        ->set('byokEnabled', true)
        ->set('hostedAiEnabled', false)
        ->set('monthlyUsdLimit', 999)
        ->set('maxConcurrency', 9);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('ai.credential.destroy'))
        ->assertRedirect(route('ai.edit'));

    $auditCountAfterRevoke = AiOperationAudit::query()->where('account_id', $account->id)->count();

    $staleComponent->call('saveSettings')->assertHasErrors('byokEnabled');

    expect($settings->refresh()->byok_enabled)->toBeFalse()
        ->and($settings->hosted_ai_enabled)->toBeTrue()
        ->and((float) $settings->monthly_usd_limit)->toBe(40.0)
        ->and($settings->max_concurrency)->toBe(2)
        ->and(AiProviderCredential::query()->where('account_id', $account->id)->firstOrFail()->revoked_at)->not->toBeNull()
        ->and(AiOperationAudit::query()->where('account_id', $account->id)->count())->toBe($auditCountAfterRevoke);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('ai.credential.store'), [
            'openrouter_api_key' => 'sk-or-v1-new-active-secret-987654321',
        ])
        ->assertRedirect(route('ai.edit'));

    Livewire::actingAs($user)->test(Ai::class)
        ->set('byokEnabled', true)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect($settings->refresh()->byok_enabled)->toBeTrue()
        ->and(AiProviderCredential::query()->where('account_id', $account->id)->firstOrFail()->revoked_at)->toBeNull();
});

test('an empty custom model list is rejected without partially saving preferences', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Ai::class)
        ->set('modelModes.short_text', 'custom')
        ->set('models.short_text', [])
        ->call('saveModels')
        ->assertHasErrors('models.short_text');

    expect($user->aiModelPreferences()->exists())->toBeFalse();
});

test('image model routing rejects more models than the generation fanout can use', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Ai::class)
        ->set('modelModes.image', 'custom')
        ->set('models.image', ['vendor/image-a', 'vendor/image-b', 'vendor/image-c', 'vendor/image-d'])
        ->call('saveModels')
        ->assertHasErrors('models.image');

    expect($user->aiModelPreferences()->exists())->toBeFalse();
});

test('demoted or removed admins cannot partially mutate credentials settings models or audits', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $account = $owner->currentAccount;
    $account->members()->attach($admin, ['role' => AccountRole::Admin]);
    $admin->forceFill(['current_account_id' => $account->id])->save();
    $settings = AiAccountSetting::factory()->for($owner)->create([
        'account_id' => $account->id,
        'max_concurrency' => 2,
        'monthly_usd_limit' => 40,
    ]);
    $settingsComponent = Livewire::actingAs($admin)->test(Ai::class)
        ->set('maxConcurrency', 9)
        ->set('monthlyUsdLimit', 999);

    expect($admin->can('manageAi', $account))->toBeTrue();

    DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $admin->id)
        ->update(['role' => AccountRole::Member->value]);
    app()->forgetScopedInstances();

    $settingsComponent->call('saveSettings')->assertForbidden();

    $key = 'sk-or-v1-blocked-admin-secret-123456789';
    $this->actingAs($admin)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('ai.credential.store'), ['openrouter_api_key' => $key])
        ->assertForbidden();

    DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $admin->id)
        ->update(['role' => AccountRole::Admin->value]);
    app()->forgetScopedInstances();
    $modelsComponent = Livewire::actingAs($admin)->test(Ai::class)
        ->set('modelModes.short_text', 'custom')
        ->set('models.short_text', ['openai/gpt-4.1-mini']);

    DB::table('account_user')
        ->where('account_id', $account->id)
        ->where('user_id', $admin->id)
        ->delete();
    app()->forgetScopedInstances();

    $modelsComponent->call('saveModels')->assertForbidden();

    expect($settings->refresh()->max_concurrency)->toBe(2)
        ->and((float) $settings->monthly_usd_limit)->toBe(40.0)
        ->and(AiProviderCredential::query()->where('account_id', $account->id)->exists())->toBeFalse()
        ->and(AiOperationAudit::query()->where('account_id', $account->id)->exists())->toBeFalse()
        ->and($account->aiModelPreferences()->exists())->toBeFalse();
});
