<x-layouts::app :title="$guide->label()">
    <section class="mx-auto max-w-6xl">
        <div class="flex flex-wrap items-end justify-between gap-5 border-b border-ink/10 pb-8 dark:border-white/10">
            <div>
                <a href="{{ route('guides.index') }}" wire:navigate class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Guides') }}</a>
                <h1 class="mt-4 max-w-[22ch] font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ $guide->label() }}</h1>
                <p class="mt-5 max-w-[50ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $guide->summary() }}</p>
            </div>
            <flux:button variant="ghost" size="sm" :href="route('advisor')" wire:navigate icon="sparkles">{{ __('Ask the Advisor') }}</flux:button>
        </div>

        <div class="mt-8 grid gap-8 @container lg:grid-cols-[15fr_9fr]">
            <div>
                <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Your next checklist') }}</p>
                <ol role="list" class="mt-4 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                    @forelse ($roadmapItems as $item)
                        <li class="py-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-lg font-semibold text-ink dark:text-white">{{ $item->title }}</p>
                                    <p class="mt-2 max-w-[65ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $item->whyItMatters }}</p>
                                </div>
                                <span class="shrink-0 rounded-full bg-sage px-3 py-1 text-base font-medium text-moss sm:text-sm dark:bg-white/10 dark:text-sage">{{ $item->status->label() }}</span>
                            </div>
                            @if ($item->href !== null)
                                <a href="{{ $item->href }}" wire:navigate class="mt-3 inline-block text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ $item->hrefLabel ?? __('Open the next step') }}</a>
                            @endif
                        </li>
                    @empty
                        <li class="py-5 text-base/7 text-muted dark:text-zinc-300">{{ __('Your profile does not currently have a checklist item in this guide.') }}</li>
                    @endforelse
                </ol>
            </div>

            <aside class="space-y-8">
                <div class="rounded-3xl bg-cream p-6 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10">
                    <p class="font-mono text-base font-medium tracking-wide text-gold sm:text-sm">{{ __('Professional review') }}</p>
                    <h2 class="mt-3 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Know when to pause.') }}</h2>
                    <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('This guide can organize the questions and sources. Confirm a decision with a qualified :reviewer when it depends on your exact filing, tax, payroll, or legal situation.', ['reviewer' => $guide->reviewerLabel()]) }}</p>
                </div>

                <div>
                    <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Related tasks') }}</p>
                    <div class="mt-4 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                        @forelse ($tasks as $task)
                            <div class="py-4">
                                <p class="text-base font-semibold text-ink dark:text-white">{{ $task->title }}</p>
                                <p class="mt-1 text-base/7 text-muted dark:text-zinc-300">{{ __('Due :date', ['date' => $task->due_on?->format('M j, Y') ?? __('when ready')]) }}</p>
                            </div>
                        @empty
                            <p class="py-4 text-base/7 text-muted dark:text-zinc-300">{{ __('No open recurring task is currently tied to this guide.') }}</p>
                        @endforelse
                    </div>
                </div>
            </aside>
        </div>

        <div class="mt-10 border-t border-ink/10 pt-8 dark:border-white/10">
            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Source library') }}</p>
            <div class="mt-4 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                @forelse ($articles as $article)
                    <a href="{{ route('knowledge.articles.show', $article->slug) }}" wire:navigate class="flex items-start justify-between gap-4 py-4">
                        <span class="min-w-0">
                            <span class="block text-base font-semibold text-ink dark:text-white">{{ $article->title }}</span>
                            <span class="mt-1 block text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $article->source_summary }}</span>
                        </span>
                        <span class="shrink-0 text-base font-medium text-moss sm:text-sm dark:text-sage">{{ $article->freshnessStatus()->label() }}</span>
                    </a>
                @empty
                    <p class="py-5 text-base/7 text-muted dark:text-zinc-300">{{ __('No source-backed article is available for this guide yet.') }}</p>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts::app>
