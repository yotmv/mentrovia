<x-layouts::app :title="__('Dashboard')">
    <section class="w-full @container">
        @if ($business === null)
            <div class="mx-auto flex min-h-[32rem] max-w-2xl items-center">
                <div class="w-full rounded-3xl bg-cream p-8 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-12">
                    <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Your Mentrovia workspace') }}</p>
                    <h1 class="mt-4 max-w-[20ch] font-display text-4xl tracking-tight text-balance text-ink dark:text-white sm:text-5xl">{{ __('Begin with the business you have in mind.') }}</h1>
                    <p class="mt-5 max-w-[50ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Answer a few questions about your business and we will build your personalized Texas setup roadmap, risk flags, and task list.') }}</p>
                    <flux:button variant="primary" :href="route('business.intake')" wire:navigate class="mt-8">
                        {{ __('Tell us about your business') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="flex flex-wrap items-end justify-between gap-5 border-b border-ink/10 pb-6 dark:border-white/10">
                <div>
                    <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Business workspace') }}</p>
                    <h1 class="mt-3 font-display text-4xl tracking-tight text-balance text-ink dark:text-white sm:text-5xl">{{ $business->displayName() }}</h1>
                    <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $business->stage?->label() }} · {{ $business->city }}, {{ $business->county }} {{ __('County') }}, TX</p>
                </div>
                <flux:button variant="ghost" size="sm" :href="route('business.intake')" wire:navigate icon="pencil-square">
                    {{ __('Edit profile') }}
                </flux:button>
            </div>

            <div class="mt-6 grid gap-5 @4xl:grid-cols-[11fr_13fr]">
                <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p class="text-sm font-medium text-muted dark:text-zinc-400">{{ __('Business setup score') }}</p>
                            <p class="mt-2 font-display text-5xl tabular-nums text-ink dark:text-white">{{ $setupScore }}<span class="font-sans text-base text-muted dark:text-zinc-400">/100</span></p>
                        </div>
                        <a href="{{ route('business.intake') }}" wire:navigate class="text-sm font-medium text-moss hover:text-ink dark:text-sage dark:hover:text-white">{{ __('Review profile') }}</a>
                    </div>
                    <div class="mt-6 h-2 overflow-hidden rounded-full bg-sage dark:bg-zinc-800">
                        <div class="h-full rounded-full bg-moss" style="--setup-progress: {{ $setupScore }}%; width: var(--setup-progress);"></div>
                    </div>
                    @if ($missingSetupItems !== [])
                        <div class="mt-6 border-t border-ink/10 pt-5 dark:border-white/10">
                            <p class="text-sm font-medium text-ink dark:text-white">{{ __('Still missing') }}</p>
                            <ul role="list" class="mt-3 grid gap-2">
                                @foreach ($missingSetupItems as $item)
                                    <li class="flex gap-3 text-base/7 text-muted dark:text-zinc-300">
                                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-gold"></span>
                                        <span>{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-muted dark:text-zinc-400">{{ __('Texas compliance roadmap') }}</p>
                            <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('The next work, in order.') }}</h2>
                        </div>
                        <a href="{{ route('roadmap') }}" wire:navigate class="text-sm font-medium text-moss hover:text-ink dark:text-sage dark:hover:text-white">{{ __('View roadmap') }}</a>
                    </div>
                    <ol role="list" class="mt-6 grid gap-4 @md:grid-cols-3">
                        @forelse ($nextActions->take(3) as $item)
                            <li class="border-t border-ink/10 pt-4 dark:border-white/10">
                                <p class="text-sm font-medium text-moss dark:text-sage">{{ $item->priority->label() }}</p>
                                <p class="mt-2 text-base/7 font-medium text-ink dark:text-white">{{ $item->title }}</p>
                                @if ($item->href !== null)
                                    <a href="{{ $item->href }}" wire:navigate class="mt-3 text-sm font-medium text-muted hover:text-moss dark:text-zinc-400 dark:hover:text-sage">{{ $item->hrefLabel ?? __('Open the guide') }}</a>
                                @endif
                            </li>
                        @empty
                            <li class="text-base/7 text-muted dark:text-zinc-300">{{ __('Your current roadmap does not have any immediate actions.') }}</li>
                        @endforelse
                    </ol>
                </div>
            </div>

            <div class="mt-5 grid gap-5 @4xl:grid-cols-[11fr_13fr]">
                <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-muted dark:text-zinc-400">{{ __('Risk flags') }}</p>
                            <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Stay ahead of costly surprises.') }}</h2>
                        </div>
                        <a href="{{ route('advisor') }}" wire:navigate class="text-sm font-medium text-moss hover:text-ink dark:text-sage dark:hover:text-white">{{ __('Ask the Advisor') }}</a>
                    </div>
                    @if ($riskFlags === [])
                        <p class="mt-6 border-t border-ink/10 pt-5 text-base/7 text-muted dark:border-white/10 dark:text-zinc-300">{{ __('No risk flags — nice work. Your current business profile does not show an immediate item that needs attention.') }}</p>
                    @else
                        <div class="mt-6 grid gap-4 border-t border-ink/10 pt-5 dark:border-white/10">
                            @foreach ($riskFlags as $flag)
                                <div>
                                    <div class="flex flex-wrap items-center gap-3">
                                        <span class="rounded-full bg-rust/10 px-3 py-1 text-sm font-medium text-rust dark:bg-rust/20">{{ $flag->label() }}</span>
                                        <a href="{{ $flag->actionUrl() }}" wire:navigate class="text-sm font-medium text-moss hover:text-ink dark:text-sage dark:hover:text-white">{{ $flag->actionLabel() }}</a>
                                    </div>
                                    <p class="mt-2 text-base/7 text-muted dark:text-zinc-300">{{ $flag->description() }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-3xl bg-white p-5 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-muted dark:text-zinc-400">{{ __('Upcoming tasks') }}</p>
                            <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Keep your operating rhythm.') }}</h2>
                        </div>
                        <a href="{{ route('tasks.index') }}" wire:navigate class="text-sm font-medium text-moss hover:text-ink dark:text-sage dark:hover:text-white">{{ __('View all tasks') }}</a>
                    </div>
                    @if ($upcomingTasks->isEmpty())
                        <p class="mt-6 border-t border-ink/10 pt-5 text-base/7 text-muted dark:border-white/10 dark:text-zinc-300">{{ __('No open upcoming tasks. Your current generated list is clear.') }}</p>
                    @else
                        <div class="mt-6 grid gap-4 border-t border-ink/10 pt-5 dark:border-white/10 @md:grid-cols-2">
                            @foreach ($upcomingTasks as $task)
                                <div class="border-s-2 border-s-sage ps-4 dark:border-s-zinc-700">
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="min-w-0 text-base/7 font-medium text-ink dark:text-white">{{ $task->title }}</p>
                                        <p class="shrink-0 text-sm font-medium tabular-nums text-muted dark:text-zinc-400">{{ $task->due_on?->format('M j') }}</p>
                                    </div>
                                    <p class="mt-1 text-sm text-muted dark:text-zinc-400">{{ $task->frequency->label() }} · {{ $task->category->label() }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="mt-8 border-t border-ink/10 pt-6 dark:border-white/10">
                <x-advisory-disclaimer />
            </div>
        @endif
    </section>
</x-layouts::app>
