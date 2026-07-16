<section class="mx-auto max-w-4xl" aria-labelledby="profile-history-title">
    <div class="flex flex-col gap-4 border-b border-ink/10 pb-7 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Company profile') }}</p>
            <h1 id="profile-history-title" class="mt-2 font-display text-4xl tracking-tight text-ink sm:text-5xl dark:text-white">{{ __('Change history') }}</h1>
            <p class="mt-3 max-w-2xl text-base/7 text-muted dark:text-zinc-300">{{ __('A read-only record of profile facts that changed. Historical values remain encrypted at rest.') }}</p>
        </div>
        <flux:button :href="route('business.edit')" wire:navigate variant="ghost" icon="arrow-left">{{ __('Profile') }}</flux:button>
    </div>

    <ol class="relative mt-8 border-s border-ink/15 ps-6 dark:border-white/15" aria-label="{{ __('Profile revision timeline') }}">
        @forelse ($this->timeline as $entry)
            @php($version = $entry['version'])
            <li class="relative pb-10 last:pb-0">
                <span class="absolute -start-[1.85rem] top-1 size-3 rounded-full bg-moss ring-4 ring-paper dark:ring-zinc-950" aria-hidden="true"></span>
                <div class="rounded-2xl bg-cream p-5 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="font-display text-2xl text-ink dark:text-white">{{ __('Revision :revision', ['revision' => $version->revision]) }}</h2>
                            <p class="mt-1 text-sm text-muted dark:text-zinc-400">{{ $version->created_at?->format('M j, Y · g:i A') }} · {{ $version->creator?->name ?? __('Former or system user') }}</p>
                        </div>
                        <flux:badge>{{ str($version->source->value)->replace('_', ' ')->title() }}</flux:badge>
                    </div>
                    @if ($entry['changes'] === [])
                        <p class="mt-5 text-sm text-muted dark:text-zinc-400">{{ __('Legacy profile baseline recorded. No earlier version is available for comparison.') }}</p>
                    @else
                        <dl class="mt-5 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                            @foreach ($entry['changes'] as $change)
                                <div class="grid gap-2 py-4 sm:grid-cols-[9rem_1fr] sm:gap-5">
                                    <dt class="text-sm font-semibold text-ink dark:text-white">{{ $this->fieldLabel($change['field']) }}</dt>
                                    <dd class="flex flex-wrap items-center gap-2 text-sm text-muted dark:text-zinc-300">
                                        <span class="break-all">{{ $this->displayValue($change['field'], $change['before']) }}</span>
                                        <flux:icon.arrow-right class="size-4 shrink-0" aria-label="{{ __('changed to') }}" />
                                        <span class="break-all font-medium text-ink dark:text-white">{{ $this->displayValue($change['field'], $change['after']) }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </li>
        @empty
            <li class="rounded-2xl bg-cream p-6 text-base/7 text-muted ring-1 ring-ink/10 dark:bg-zinc-900 dark:text-zinc-300 dark:ring-white/10">{{ __('No versioned profile update has been recorded yet. Viewing this page never changes the profile.') }}</li>
        @endforelse
    </ol>
    @if ($this->versions->hasPages())
        <div class="mt-8">{{ $this->versions->links() }}</div>
    @endif
</section>
