<x-layouts::app :title="__('Your plan is ready')">
    <section class="mx-auto max-w-6xl">
        <div class="border-b border-ink/10 pb-8 dark:border-white/10">
            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Your plan is ready') }}</p>
            <h1 class="mt-4 max-w-[24ch] font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ __('A clear starting point for :name.', ['name' => $business->displayName()]) }}</h1>
            @if ($finalizedTrack === \App\Enums\BusinessOnboardingTrack::EstablishedCompany)
                <p class="mt-5 max-w-[52ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('We used your established company’s operating baseline to prioritize the work below. Keep the profile current as the business changes.') }}</p>
            @else
                <p class="mt-5 max-w-[48ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Your profile points to a :stage business. Start with the work below, then use Today to keep the next steps visible.', ['stage' => $business->stage?->label()]) }}</p>
            @endif
        </div>

        <div class="mt-8 grid gap-6 @container lg:grid-cols-[9fr_15fr]">
            <aside class="rounded-3xl bg-ink p-6 text-white dark:bg-zinc-900 sm:p-8">
                <p class="text-base font-medium text-white/70 sm:text-sm">{{ __('Business setup score') }}</p>
                <p class="mt-3 font-display text-6xl tabular-nums">{{ $setupScore }}<span class="font-sans text-base text-white/60">/100</span></p>
                <div class="mt-6 h-2 overflow-hidden rounded-full bg-white/15">
                    <div class="h-full rounded-full bg-sage" style="--setup-progress: {{ $setupScore }}%; width: var(--setup-progress);"></div>
                </div>
                <p class="mt-6 text-base/7 text-pretty text-white/70">{{ __('This score changes as you keep your profile accurate and complete the work that applies to your business.') }}</p>
            </aside>

            <div class="rounded-3xl bg-white p-6 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-8">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Start here') }}</p>
                        <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Your first three actions') }}</h2>
                    </div>
                    <a href="{{ route('roadmap') }}" wire:navigate class="text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ __('See the full plan') }}</a>
                </div>
                <ol role="list" class="mt-6 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                    @forelse ($nextActions as $item)
                        @php($template = $roadmapTemplates->get($item->template_key))
                        <li class="py-5">
                            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ $item->priority->label() }}</p>
                            <h3 class="mt-2 text-lg font-semibold text-ink dark:text-white">{{ $template?->title ?? $item->title }}</h3>
                            <p class="mt-2 max-w-[65ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $template?->whyItMatters ?? $item->why_it_matters }}</p>
                            <p class="mt-2 text-sm text-muted dark:text-zinc-400">{{ $item->assignee?->name ?? __('Unassigned') }} · {{ __('Planning target: :date', ['date' => $item->due_on?->format('M j, Y') ?? __('not set')]) }}</p>
                            @if ($template?->href !== null)
                                <a href="{{ $template->href }}" wire:navigate class="mt-3 inline-block text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ $template->hrefLabel ?? __('Open the module') }}</a>
                            @endif
                        </li>
                    @empty
                        <li class="py-5 text-base/7 text-muted dark:text-zinc-300">{{ __('Your current profile does not have an immediate action.') }}</li>
                    @endforelse
                </ol>
            </div>
        </div>

        @if ($firstTask !== null || $riskFlags !== [])
            <div class="mt-6 grid gap-6 @container lg:grid-cols-2">
                @if ($firstTask !== null)
                    <div class="border-t border-ink/10 pt-5 dark:border-white/10">
                        <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('First recurring task') }}</p>
                        <h2 class="mt-2 text-lg font-semibold text-ink dark:text-white">{{ $firstTask->title }}</h2>
                        <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Due :date · :frequency', ['date' => $firstTask->due_on?->format('M j, Y') ?? __('when ready'), 'frequency' => $firstTask->frequency->label()]) }}</p>
                    </div>
                @endif
                @if ($riskFlags !== [])
                    <div class="border-t border-ink/10 pt-5 dark:border-white/10">
                        <p class="font-mono text-base font-medium tracking-wide text-rust sm:text-sm">{{ __('Keep an eye on') }}</p>
                        <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ trans_choice(':count item needs your attention based on your profile.', count($riskFlags), ['count' => count($riskFlags)]) }}</p>
                    </div>
                @endif
            </div>
        @endif

        <div class="mt-10 flex flex-wrap items-center gap-4 border-t border-ink/10 pt-8 dark:border-white/10">
            <flux:button variant="primary" :href="route('dashboard')" wire:navigate icon="arrow-right">{{ __('Go to Today') }}</flux:button>
            <a href="{{ route('business.overview') }}" wire:navigate class="text-base font-medium text-ink underline decoration-moss/40 underline-offset-4 sm:text-sm dark:text-white">{{ __('Review your profile') }}</a>
        </div>
    </section>
</x-layouts::app>
