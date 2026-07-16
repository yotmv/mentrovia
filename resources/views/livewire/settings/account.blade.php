<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout wide :heading="__('Workspace')" :subheading="__('Manage access to :account and switch between your workspaces.', ['account' => $this->workspace->name])">
        <div class="space-y-10">
            <section class="space-y-4" aria-labelledby="workspace-switcher-heading">
                <div>
                    <flux:heading id="workspace-switcher-heading">{{ __('Your workspaces') }}</flux:heading>
                    <flux:subheading>{{ __('Changing workspaces reloads the application in the selected company context.') }}</flux:subheading>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($this->accounts as $account)
                        <flux:card wire:key="workspace-{{ $account->id }}" class="flex items-center justify-between gap-4">
                            <div class="min-w-0">
                                <flux:heading class="truncate">{{ $account->name }}</flux:heading>
                                <flux:text size="sm">{{ __(ucfirst($account->membership->role->value)) }}</flux:text>
                            </div>

                            @if ($account->id === $this->workspace->id)
                                <flux:badge color="green">{{ __('Current') }}</flux:badge>
                            @else
                                <flux:button type="button" size="sm" wire:click="switchAccount({{ $account->id }})">
                                    {{ __('Switch') }}
                                </flux:button>
                            @endif
                        </flux:card>
                    @endforeach
                </div>
            </section>

            @if (in_array($this->role, [\App\Enums\AccountRole::Owner, \App\Enums\AccountRole::Admin], true))
                <section class="space-y-4" aria-labelledby="workspace-invite-heading">
                    <div>
                        <flux:heading id="workspace-invite-heading">{{ __('Invite a teammate') }}</flux:heading>
                        <flux:subheading>{{ __('Invitations expire after seven days and only the invited verified email can accept.') }}</flux:subheading>
                    </div>

                    <form wire:submit="invite" class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_10rem_auto] sm:items-end">
                        <flux:input wire:model="inviteEmail" type="email" :label="__('Email')" autocomplete="email" />
                        <flux:select wire:model="inviteRole" :label="__('Role')">
                            <flux:select.option value="member">{{ __('Member') }}</flux:select.option>
                            @if ($this->role === \App\Enums\AccountRole::Owner)
                                <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
                            @endif
                        </flux:select>
                        <flux:button type="submit" variant="primary">{{ __('Send invite') }}</flux:button>
                    </form>
                </section>
            @endif

            @if ($this->pendingInvitations->isNotEmpty())
                <section class="space-y-4" aria-labelledby="pending-invitations-heading">
                    <flux:heading id="pending-invitations-heading">{{ __('Pending invitations') }}</flux:heading>

                    <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($this->pendingInvitations as $invitation)
                            <div wire:key="invitation-{{ $invitation->public_id }}" class="flex flex-col justify-between gap-3 p-4 sm:flex-row sm:items-center">
                                <div class="min-w-0">
                                    <flux:heading class="truncate">{{ $invitation->email }}</flux:heading>
                                    <flux:text size="sm">{{ __(ucfirst($invitation->role->value)) }} · {{ __('Expires :date', ['date' => $invitation->expires_at->toFormattedDateString()]) }}</flux:text>
                                </div>

                                @if ($this->role === \App\Enums\AccountRole::Owner || ($this->role === \App\Enums\AccountRole::Admin && $invitation->role === \App\Enums\AccountRole::Member))
                                    <flux:button type="button" size="sm" variant="danger" wire:click="revokeInvitation('{{ $invitation->public_id }}')">
                                        {{ __('Revoke') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if (in_array($this->role, [\App\Enums\AccountRole::Owner, \App\Enums\AccountRole::Admin], true))
            <section class="space-y-4" aria-labelledby="workspace-members-heading">
                <div>
                    <flux:heading id="workspace-members-heading">{{ __('Members') }}</flux:heading>
                    <flux:subheading>{{ __('Owners control administrators. Administrators can manage members only.') }}</flux:subheading>
                </div>

                <div class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->members as $member)
                        <div wire:key="member-{{ $member->id }}" class="flex flex-col justify-between gap-4 p-4 sm:flex-row sm:items-center">
                            <div class="min-w-0">
                                <flux:heading class="truncate">{{ $member->name }}</flux:heading>
                                <flux:text class="truncate" size="sm">{{ $member->email }}</flux:text>
                            </div>

                            <div class="flex flex-wrap items-end gap-2">
                                @if ($this->role === \App\Enums\AccountRole::Owner && $member->membership->role !== \App\Enums\AccountRole::Owner)
                                    <flux:select wire:model="memberRoles.{{ $member->id }}" :label="__('Role')" size="sm">
                                        <flux:select.option value="member">{{ __('Member') }}</flux:select.option>
                                        <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
                                    </flux:select>
                                    <flux:button type="button" size="sm" wire:click="updateRole({{ $member->id }})">{{ __('Update') }}</flux:button>
                                @else
                                    <flux:badge>{{ __(ucfirst($member->membership->role->value)) }}</flux:badge>
                                @endif

                                @if ($member->membership->role !== \App\Enums\AccountRole::Owner && ($this->role === \App\Enums\AccountRole::Owner || ($this->role === \App\Enums\AccountRole::Admin && $member->membership->role === \App\Enums\AccountRole::Member)))
                                    <flux:button type="button" size="sm" variant="danger" wire:click="removeMember({{ $member->id }})">
                                        {{ __('Remove') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
            @endif

            @if ($this->role === \App\Enums\AccountRole::Owner)
                <section class="space-y-4" aria-labelledby="transfer-workspace-heading">
                    <div>
                        <flux:heading id="transfer-workspace-heading">{{ __('Transfer ownership') }}</flux:heading>
                        <flux:subheading>{{ __('The new owner receives full workspace control. You will become an administrator.') }}</flux:subheading>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model="transferTargetUserId" :label="__('New owner')" :placeholder="__('Select a member')">
                            @foreach ($this->members as $member)
                                @if ($member->id !== auth()->id())
                                    <flux:select.option :value="$member->id">{{ $member->name }} — {{ $member->email }}</flux:select.option>
                                @endif
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="currentPassword" type="password" :label="__('Current password')" autocomplete="current-password" viewable />
                    </div>

                    <flux:text size="sm">{{ __('A recently confirmed password can be reused for sensitive changes.') }}</flux:text>
                    <flux:button type="button" variant="danger" wire:click="transferOwnership">{{ __('Transfer ownership') }}</flux:button>
                </section>
            @elseif (in_array($this->role, [\App\Enums\AccountRole::Admin, \App\Enums\AccountRole::Member], true))
                <section class="space-y-4" aria-labelledby="leave-workspace-heading">
                    <div>
                        <flux:heading id="leave-workspace-heading">{{ __('Leave workspace') }}</flux:heading>
                        <flux:subheading>{{ __('Your other workspace will become current. If you have none, a new personal workspace will be created.') }}</flux:subheading>
                    </div>
                    <flux:button type="button" variant="danger" wire:click="leave">{{ __('Leave workspace') }}</flux:button>
                </section>
            @endif

            @if ($this->role === \App\Enums\AccountRole::Owner)
                <section class="space-y-4 border-t border-red-200 pt-8 dark:border-red-900" aria-labelledby="delete-workspace-heading">
                    <div>
                        <flux:heading id="delete-workspace-heading">{{ __('Delete workspace') }}</flux:heading>
                        <flux:subheading>{{ __('Permanently erase this company workspace, including its projects, business data, generated content, and stored files.') }}</flux:subheading>
                    </div>

                    <flux:modal.trigger name="confirm-workspace-deletion">
                        <flux:button type="button" variant="danger">{{ __('Delete workspace') }}</flux:button>
                    </flux:modal.trigger>

                    <flux:modal name="confirm-workspace-deletion" :show="$errors->hasAny(['workspaceName', 'currentPassword', 'workspaceDeletion'])" focusable class="max-w-lg">
                        <form wire:submit="deleteWorkspace" class="space-y-6">
                            <div>
                                <flux:heading size="lg">{{ __('Delete :workspace?', ['workspace' => $this->workspace->name]) }}</flux:heading>
                                <flux:subheading>
                                    {{ __('This cannot be undone. Access is revoked immediately, then data and files are securely erased in the background. Member user accounts and the permanent AI security audit ledger are retained. Enter the workspace name exactly and confirm your password.') }}
                                </flux:subheading>
                            </div>

                            <flux:input wire:model="workspaceName" :label="__('Workspace name')" autocomplete="off" />
                            <flux:input wire:model="currentPassword" type="password" :label="__('Current password')" autocomplete="current-password" viewable />
                            <flux:error name="workspaceDeletion" />

                            <div class="flex justify-end gap-2">
                                <flux:modal.close>
                                    <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                                </flux:modal.close>
                                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="deleteWorkspace">
                                    <span wire:loading.remove wire:target="deleteWorkspace">{{ __('Permanently delete workspace') }}</span>
                                    <span wire:loading wire:target="deleteWorkspace">{{ __('Starting secure deletion…') }}</span>
                                </flux:button>
                            </div>
                        </form>
                    </flux:modal>
                </section>
            @endif
        </div>
    </x-settings.layout>
</section>
