@php
    $converter = new League\CommonMark\GithubFlavoredMarkdownConverter([
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ]);
@endphp

<x-layouts::app :title="$article->title">
    <section class="w-full">
        <flux:link :href="route('knowledge.articles.index')" wire:navigate class="text-sm">
            {{ __('← Back to Knowledge') }}
        </flux:link>

        <div class="mt-4">
            <flux:heading size="xl">{{ $article->title }}</flux:heading>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <flux:badge size="sm" color="zinc">{{ $article->category->label() }}</flux:badge>
            <flux:badge size="sm" color="zinc">{{ $article->jurisdiction }}</flux:badge>
            <flux:badge size="sm" :color="$article->freshnessStatus()->color()">{{ $article->freshnessStatus()->label() }}</flux:badge>
            <flux:badge size="sm" :color="match ($article->risk_level) {
                \App\Enums\RiskLevel::High => 'red',
                \App\Enums\RiskLevel::Medium => 'amber',
                \App\Enums\RiskLevel::Low => 'zinc',
            }">Risk: {{ $article->risk_level->label() }}</flux:badge>
            <flux:badge size="sm" :color="match ($article->status) {
                \App\Enums\ArticleStatus::Published => 'green',
                \App\Enums\ArticleStatus::NeedsReview => 'amber',
                \App\Enums\ArticleStatus::Draft => 'zinc',
                \App\Enums\ArticleStatus::Archived => 'zinc',
            }">{{ $article->status->label() }}</flux:badge>
        </div>

        @if ($article->freshnessStatus() === \App\Enums\FreshnessStatus::MissingSources)
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                <flux:heading size="sm" class="text-red-700 dark:text-red-300">{{ __('Missing sources') }}</flux:heading>
                <flux:text size="sm" class="mt-1 text-red-700 dark:text-red-300">
                    {{ __('This article has no source links. Verify any guidance with the appropriate government agency before relying on it.') }}
                </flux:text>
            </div>
        @endif

        @if ($article->isStale())
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                <flux:heading size="sm" class="text-red-700 dark:text-red-300">{{ __('Stale content') }}</flux:heading>
                <flux:text size="sm" class="mt-1 text-red-700 dark:text-red-300">
                    {{ __('This article is past its scheduled review date. Confirm all guidance with the official source below before acting.') }}
                </flux:text>
            </div>
        @endif

        @if ($article->risk_level === \App\Enums\RiskLevel::High)
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-800 dark:bg-red-900/20">
                <flux:heading size="sm" class="text-red-700 dark:text-red-300">{{ __('High-risk content') }}</flux:heading>
                <flux:text size="sm" class="mt-1 text-red-700 dark:text-red-300">
                    {{ __('This article covers sensitive legal, tax, or payroll topics. Confirm all deadlines, rates, and thresholds with the official source below and review with a qualified professional before acting.') }}
                </flux:text>
            </div>
        @endif

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <div class="prose max-w-[75ch]">
                    {!! $converter->convert($article->body_markdown) !!}
                </div>
            </div>

            <div class="space-y-6">
                <div class="rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
                    <flux:heading size="sm">{{ __('Freshness') }}</flux:heading>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="font-medium text-ink/60 dark:text-zinc-400">{{ __('Last verified') }}</dt>
                            <dd>{{ $article->last_verified_at?->format('M j, Y') ?? __('Not yet verified') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-ink/60 dark:text-zinc-400">{{ __('Next review') }}</dt>
                            <dd>{{ $article->next_review_at?->format('M j, Y') ?? __('No review scheduled') }}</dd>
                        </div>
                        <div>
                            <dt class="font-medium text-ink/60 dark:text-zinc-400">{{ __('Version') }}</dt>
                            <dd>{{ $article->version }}</dd>
                        </div>
                    </dl>
                    <flux:badge size="sm" :color="$article->freshnessStatus()->color()" class="mt-3">{{ $article->freshnessStatus()->label() }}</flux:badge>
                </div>

                <div class="rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
                    <flux:heading size="sm">{{ __('Sources') }}</flux:heading>
                    @if ($article->sources->isEmpty())
                        <flux:text size="sm" class="mt-3 text-ink/60 dark:text-zinc-400">
                            {{ __('No source links are available for this article. Verify any guidance with the appropriate government agency before relying on it.') }}
                        </flux:text>
                    @else
                        @if ($article->source_summary)
                            <flux:text size="sm" class="mt-2">{{ $article->source_summary }}</flux:text>
                        @endif
                        <ul class="mt-3 space-y-2">
                            @foreach ($article->sources as $source)
                                <li>
                                    <a
                                        href="{{ $source->source_url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-sm text-moss hover:underline dark:text-sage"
                                    >
                                        {{ $source->source_name }}
                                    </a>
                                    <flux:text size="sm" class="text-ink/60 dark:text-zinc-400">
                                        {{ $source->source_type->label() }}@if ($source->retrieved_at) · {{ __('Retrieved') }} {{ $source->retrieved_at->format('M j, Y') }}@endif
                                    </flux:text>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-10">
            <x-advisory-disclaimer />
        </div>
    </section>
</x-layouts::app>
