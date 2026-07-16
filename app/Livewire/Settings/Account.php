<?php

namespace App\Livewire\Settings;

use App\Actions\Accounts\CreateAccountInvitation;
use App\Actions\Accounts\RemoveAccountMember;
use App\Actions\Accounts\RevokeAccountInvitation;
use App\Actions\Accounts\StartWorkspaceErasure;
use App\Actions\Accounts\SwitchCurrentAccount;
use App\Actions\Accounts\TransferAccountOwnership;
use App\Actions\Accounts\UpdateAccountMemberRole;
use App\Enums\AccountRole;
use App\Models\Account as WorkspaceAccount;
use App\Models\AccountInvitation;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Account extends Component
{
    private CurrentAccount $currentAccount;

    public string $inviteEmail = '';

    public string $inviteRole = 'member';

    /** @var array<int|string, string> */
    public array $memberRoles = [];

    public string $currentPassword = '';

    public string $transferTargetUserId = '';

    public string $workspaceName = '';

    public function boot(CurrentAccount $currentAccount): void
    {
        $this->currentAccount = $currentAccount;
        $this->currentAccount->resolve($this->user());
    }

    public function mount(): void
    {
        foreach ($this->members() as $member) {
            $membership = $member->getRelation('membership');

            if ($membership instanceof AccountMembership) {
                $this->memberRoles[$member->id] = $membership->role->value;
            }
        }
    }

    public function invite(CreateAccountInvitation $createInvitation): void
    {
        $validated = $this->validate([
            'inviteEmail' => ['required', 'string', 'email:rfc', 'max:254'],
            'inviteRole' => ['required', Rule::in([AccountRole::Admin->value, AccountRole::Member->value])],
        ]);

        $createInvitation->handle(
            $this->workspace(),
            $this->user(),
            $validated['inviteEmail'],
            AccountRole::from($validated['inviteRole']),
        );

        $this->reset('inviteEmail');
        unset($this->pendingInvitations);
        Flux::toast(variant: 'success', text: __('Workspace invitation sent.'));
    }

    public function revokeInvitation(string $publicId, RevokeAccountInvitation $revokeInvitation): void
    {
        $invitation = $this->workspace()->invitations()
            ->where('public_id', $publicId)
            ->first();
        abort_unless($invitation instanceof AccountInvitation, 404);

        $revokeInvitation->handle($invitation, $this->user());

        unset($this->pendingInvitations);
        Flux::toast(variant: 'success', text: __('Invitation revoked.'));
    }

    public function updateRole(int $userId, UpdateAccountMemberRole $updateRole): void
    {
        $validated = $this->validate([
            "memberRoles.{$userId}" => ['required', Rule::in([AccountRole::Admin->value, AccountRole::Member->value])],
        ]);
        $target = $this->workspace()->members()->whereKey($userId)->first();
        abort_unless($target instanceof User, 404);
        $memberRoles = $validated['memberRoles'];

        $updateRole->handle(
            $this->workspace(),
            $this->user(),
            $target,
            AccountRole::from($memberRoles[$userId]),
            $this->currentPassword,
        );

        $this->reset('currentPassword');
        unset($this->members);
        Flux::toast(variant: 'success', text: __('Member role updated.'));
    }

    public function removeMember(int $userId, RemoveAccountMember $removeMember): void
    {
        $target = $this->workspace()->members()->whereKey($userId)->first();
        abort_unless($target instanceof User, 404);
        $removeMember->handle($this->workspace(), $this->user(), $target);

        unset($this->members);
        Flux::toast(variant: 'success', text: __('Member removed.'));
    }

    public function leave(RemoveAccountMember $removeMember): void
    {
        $removeMember->handle($this->workspace(), $this->user(), $this->user());
        session()->regenerate(true);
        $this->redirectRoute('dashboard', navigate: false);
    }

    public function transferOwnership(TransferAccountOwnership $transferOwnership): void
    {
        $validated = $this->validate([
            'transferTargetUserId' => ['required', 'integer'],
        ]);
        $target = $this->workspace()->members()
            ->whereKey((int) $validated['transferTargetUserId'])
            ->first();
        abort_unless($target instanceof User, 404);

        $transferOwnership->handle(
            $this->workspace(),
            $this->user(),
            $target,
            $this->currentPassword,
        );

        $this->reset('currentPassword', 'transferTargetUserId');
        unset($this->members, $this->role);
        Flux::toast(variant: 'success', text: __('Workspace ownership transferred.'));
    }

    public function switchAccount(int $accountId, SwitchCurrentAccount $switchCurrentAccount): void
    {
        $account = $this->user()->accounts()->whereKey($accountId)->first();
        abort_unless($account instanceof WorkspaceAccount, 404);
        $switchCurrentAccount->handle($this->user(), $account);
        session()->regenerate(true);
        $this->redirectRoute('dashboard', navigate: false);
    }

    public function deleteWorkspace(StartWorkspaceErasure $startWorkspaceErasure): void
    {
        $account = $this->workspace();
        $user = $this->user();
        Gate::authorize('transferOwnership', $account);
        $validated = $this->validate([
            'workspaceName' => ['required', 'string', 'max:255'],
            'currentPassword' => ['nullable', 'string', 'max:255'],
        ]);
        $rateLimitKey = 'workspace-erasure:'.$account->id.':'.$user->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            throw ValidationException::withMessages([
                'workspaceDeletion' => __('Too many deletion attempts. Please wait before trying again.'),
            ]);
        }

        RateLimiter::increment($rateLimitKey, decaySeconds: 3600);
        $startWorkspaceErasure->handle(
            $account,
            $user,
            $validated['workspaceName'],
            $validated['currentPassword'] ?? null,
        );

        $this->reset('workspaceName', 'currentPassword');
        session()->regenerate(true);
        $this->redirectRoute('dashboard', navigate: false);
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function members(): Collection
    {
        if ($this->role() === AccountRole::Member) {
            return $this->workspace()->members()->whereKey($this->user()->id)->get();
        }

        return $this->workspace()->members()
            ->orderByRaw("CASE WHEN account_user.role = 'owner' THEN 0 WHEN account_user.role = 'admin' THEN 1 ELSE 2 END")
            ->orderBy('users.name')
            ->get();
    }

    /** @return Collection<int, WorkspaceAccount> */
    #[Computed]
    public function accounts(): Collection
    {
        return $this->user()->accounts()->orderBy('accounts.name')->get();
    }

    /** @return Collection<int, AccountInvitation> */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        if ($this->role() === AccountRole::Member) {
            return new Collection;
        }

        return $this->workspace()->invitations()
            ->pending()
            ->latest()
            ->get();
    }

    #[Computed]
    public function workspace(): WorkspaceAccount
    {
        $account = $this->currentAccount->resolve($this->user());
        Gate::authorize('view', $account);

        return $account;
    }

    #[Computed]
    public function role(): AccountRole
    {
        return $this->workspace()->roleFor($this->user())
            ?? throw new \LogicException('The current user has no workspace role.');
    }

    public function render(): View
    {
        return view('livewire.settings.account');
    }

    private function user(): User
    {
        $user = Auth::user();

        return $user instanceof User
            ? $user
            : throw new \LogicException('Workspace settings require an authenticated user.');
    }
}
