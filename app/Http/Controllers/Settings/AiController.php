<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AiAuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreOpenRouterCredentialRequest;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AiController extends Controller
{
    public function __construct(
        private CurrentAccount $currentAccount,
        private AccountMutationGate $accountMutationGate,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->accountForManagement($user);

        return view('pages.settings.ai');
    }

    public function trust(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $this->accountForManagement($user);

        return view('pages.settings.ai-trust');
    }

    public function storeCredential(StoreOpenRouterCredentialRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $key = (string) $request->validated('openrouter_api_key');

        $account = $this->accountForManagement($user);
        $this->persistCredential($account, $user, $key);

        return to_route('ai.edit')->with('status', __('OpenRouter credential saved.'));
    }

    public function destroyCredential(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $account = $this->accountForManagement($user);

        DB::transaction(function () use ($account, $user): void {
            $lockedAccount = $this->accountMutationGate->lockManagerOrFail($account->id, $user->id);
            $credential = AiProviderCredential::query()
                ->whereBelongsTo($lockedAccount)
                ->where('provider', 'openrouter')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if (! $credential instanceof AiProviderCredential) {
                return;
            }

            $credential->update(['revoked_at' => now()]);
            $this->appendCredentialAudit($lockedAccount, $user, $credential, AiAuditEvent::CredentialRevoked);
            $lockedAccount->aiAccountSetting()->update(['byok_enabled' => false]);
        }, attempts: 3);

        return to_route('ai.edit')->with('status', __('OpenRouter credential revoked.'));
    }

    private function persistCredential(Account $account, User $user, #[\SensitiveParameter] string $key): void
    {
        $key = trim($key);
        $fingerprint = hash_hmac('sha256', $key, (string) config('app.key'));

        DB::transaction(function () use ($account, $user, $key, $fingerprint): void {
            $lockedAccount = $this->accountMutationGate->lockManagerOrFail($account->id, $user->id);
            $existing = AiProviderCredential::query()
                ->whereBelongsTo($lockedAccount)
                ->where('provider', 'openrouter')
                ->lockForUpdate()
                ->first();
            $event = $existing instanceof AiProviderCredential
                ? AiAuditEvent::CredentialRotated
                : AiAuditEvent::CredentialSaved;
            $attributedUserId = $existing instanceof AiProviderCredential
                ? ($existing->user_id ?? $user->id)
                : $user->id;

            $credential = AiProviderCredential::query()->updateOrCreate(
                ['account_id' => $lockedAccount->id, 'provider' => 'openrouter'],
                [
                    'user_id' => $attributedUserId,
                    'secret' => $key,
                    'fingerprint' => $fingerprint,
                    'last_four' => Str::substr($key, -4),
                    'rotated_at' => $existing instanceof AiProviderCredential ? now() : null,
                    'revoked_at' => null,
                ],
            );

            $this->appendCredentialAudit($lockedAccount, $user, $credential, $event);
        }, attempts: 3);
    }

    private function appendCredentialAudit(Account $account, User $user, AiProviderCredential $credential, AiAuditEvent $event): void
    {
        AiOperationAudit::query()->create([
            'operation_id' => (string) Str::uuid7(),
            'account_id' => $account->id,
            'actor_user_id' => $user->id,
            'event' => $event,
            'provider' => 'openrouter',
            'credential_fingerprint' => $credential->fingerprint,
            'ip_hash' => request()->ip() ? hash_hmac('sha256', (string) request()->ip(), (string) config('app.key')) : null,
            'user_agent_hash' => request()->userAgent() ? hash_hmac('sha256', (string) request()->userAgent(), (string) config('app.key')) : null,
            'occurred_at' => now(),
        ]);
    }

    private function accountForManagement(User $user): Account
    {
        $account = $this->currentAccount->resolve($user);
        Gate::authorize('manageAi', $account);

        return $account;
    }
}
