<x-layouts::app :title="__('Workspace invitation')">
    <main id="main-content" class="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">
        <flux:card class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="xl">{{ __('Join :account', ['account' => $invitation->account->name]) }}</flux:heading>
                <flux:text>{{ __('You were invited by :name as a workspace :role.', ['name' => $invitation->inviter->name, 'role' => $invitation->role->value]) }}</flux:text>
            </div>

            <form method="POST" action="{{ $acceptUrl }}">
                @csrf
                <flux:button type="submit" variant="primary">{{ __('Accept workspace invitation') }}</flux:button>
            </form>
        </flux:card>
    </main>
</x-layouts::app>
