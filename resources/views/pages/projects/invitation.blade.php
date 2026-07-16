<x-layouts::app :title="__('Project invitation')">
    <section class="mx-auto w-full max-w-2xl">
        <div class="rounded-2xl border border-zinc-200 p-6 dark:border-zinc-700 sm:p-8">
            <flux:badge color="green">{{ __('Project invitation') }}</flux:badge>
            <flux:heading size="xl" class="mt-4">
                {{ __('Join :project', ['project' => $invitation->project->name]) }}
            </flux:heading>
            <flux:text class="mt-3">
                {{ __(':name invited you to collaborate with :permission access.', [
                    'name' => $invitation->project->owner->name,
                    'permission' => mb_strtolower($invitation->permission->label()),
                ]) }}
            </flux:text>
            <flux:text class="mt-2 text-sm">
                {{ __('This invitation expires :date and can only be accepted by the verified email address it was sent to.', [
                    'date' => $invitation->expires_at->diffForHumans(),
                ]) }}
            </flux:text>

            <form method="POST" action="{{ $acceptUrl }}" class="mt-6 flex flex-wrap items-center gap-3">
                @csrf
                <flux:button type="submit" variant="primary">{{ __('Accept invitation') }}</flux:button>
                <flux:button variant="ghost" :href="route('dashboard')">{{ __('Not now') }}</flux:button>
            </form>
        </div>
    </section>
</x-layouts::app>
