<x-layouts::app :title="__('Business')">
    <section class="mx-auto max-w-6xl">
        <div class="flex flex-wrap items-end justify-between gap-5 border-b border-ink/10 pb-7 dark:border-white/10">
            <div>
                <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Business profile') }}</p>
                <h1 class="mt-3 font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ $business->displayName() }}</h1>
                <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $business->stage?->label() }} · {{ $business->city }}, {{ $business->county }} {{ __('County') }}, TX</p>
            </div>
            <flux:button variant="primary" size="sm" :href="route('business.edit')" wire:navigate icon="pencil-square">{{ __('Edit profile') }}</flux:button>
        </div>

        <div class="mt-8 grid gap-6 @container lg:grid-cols-[9fr_15fr]">
            <div class="rounded-3xl bg-cream p-6 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-8">
                <p class="text-base font-medium text-muted sm:text-sm dark:text-zinc-400">{{ __('Profile completeness') }}</p>
                <p class="mt-3 font-display text-6xl tabular-nums text-ink dark:text-white">{{ $setupScore }}<span class="font-sans text-base text-muted dark:text-zinc-400">/100</span></p>
                <div class="mt-6 h-2 overflow-hidden rounded-full bg-sage dark:bg-zinc-800">
                    <div class="h-full rounded-full bg-moss" style="--setup-progress: {{ $setupScore }}%; width: var(--setup-progress);"></div>
                </div>
                <p class="mt-5 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Keep this profile current. It is the context behind your roadmap, risks, tasks, and guide recommendations.') }}</p>
            </div>

            <div class="divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                @foreach ([
                    __('Business') => $business->displayName(),
                    __('Industry') => $business->industry,
                    __('Structure') => $business->legal_structure->label(),
                    __('People') => trans_choice(':count owner|:count owners', $business->owner_count, ['count' => $business->owner_count]).($business->employee_count > 0 ? ' · '.trans_choice(':count employee|:count employees', $business->employee_count, ['count' => $business->employee_count]) : ''),
                    __('Location') => $business->city.', '.$business->county.' County, TX',
                ] as $label => $value)
                    <div class="grid gap-1 py-4 sm:grid-cols-[10rem_1fr] sm:gap-6">
                        <dt class="text-base font-medium text-ink sm:text-sm dark:text-white">{{ $label }}</dt>
                        <dd class="text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $value }}</dd>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-10 grid gap-8 @container lg:grid-cols-2">
            <div>
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <p class="font-mono text-base font-medium tracking-wide text-rust sm:text-sm">{{ __('Profile signals') }}</p>
                        <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('What needs attention') }}</h2>
                    </div>
                    <a href="{{ route('dashboard') }}" wire:navigate class="text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ __('Open Today') }}</a>
                </div>
                <div class="mt-6 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                    @forelse ($riskFlags as $flag)
                        <div class="py-4">
                            <h3 class="text-base font-semibold text-ink dark:text-white">{{ $flag->label() }}</h3>
                            <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $flag->description() }}</p>
                            <a href="{{ $flag->actionUrl() }}" wire:navigate class="mt-3 inline-block text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ $flag->actionLabel() }}</a>
                        </div>
                    @empty
                        <p class="py-5 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Your current profile does not show an immediate risk flag.') }}</p>
                    @endforelse
                </div>
            </div>

            <div>
                <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('How your profile changes the plan') }}</p>
                <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Your next actions') }}</h2>
                <ol role="list" class="mt-6 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                    @foreach ($nextActions as $item)
                        @php($template = $roadmapTemplates->get($item->template_key))
                        <li class="py-4">
                            <p class="text-base font-semibold text-ink dark:text-white">{{ $template?->title ?? $item->title }}</p>
                            <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $template?->whyItMatters ?? $item->why_it_matters }}</p>
                            <p class="mt-2 text-sm text-muted dark:text-zinc-400">{{ $item->assignee?->name ?? __('Unassigned') }} · {{ __('Planning target: :date', ['date' => $item->due_on?->format('M j, Y') ?? __('not set')]) }}</p>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>
</x-layouts::app>
