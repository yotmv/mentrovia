<x-layouts::app :title="__('Knowledge')">
    <section class="w-full">
        <div class="mb-8">
            <flux:heading size="xl">{{ __('Knowledge Articles') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Texas compliance and business guidance, sourced from official agencies and reviewed for freshness.') }}
            </flux:text>
        </div>

        <form method="GET" class="mb-6 flex flex-wrap items-center gap-3">
            <select name="category" class="rounded-xl px-3 py-2 text-sm ring-1 ring-ink/10 dark:ring-white/10 dark:bg-zinc-900">
                <option value="">{{ __('All categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->value }}" {{ $selectedCategory === $category->value ? 'selected' : '' }}>{{ $category->label() }}</option>
                @endforeach
            </select>

            <flux:button type="submit" variant="ghost" size="sm">{{ __('Filter') }}</flux:button>
            @if ($selectedCategory)
                <flux:link :href="route('knowledge.articles.index')" class="text-sm">{{ __('Clear') }}</flux:link>
            @endif
        </form>

        @if ($articles->isEmpty())
            <flux:text class="py-12 text-center">{{ __('No articles match the selected filters.') }}</flux:text>
        @else
            <div class="space-y-3">
                @foreach ($articles as $article)
                    <a
                        href="{{ route('knowledge.articles.show', $article->slug) }}"
                        wire:navigate
                        class="block rounded-2xl p-4 ring-1 ring-ink/10 transition hover:ring-ink/20 dark:ring-white/10 dark:hover:ring-white/20"
                    >
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading size="sm">{{ $article->title }}</flux:heading>
                            <div class="ms-auto flex items-center gap-2">
                                <flux:badge size="sm" :color="$article->freshnessStatus()->color()">{{ $article->freshnessStatus()->label() }}</flux:badge>
                                <flux:badge size="sm" :color="match ($article->risk_level) {
                                    \App\Enums\RiskLevel::High => 'red',
                                    \App\Enums\RiskLevel::Medium => 'amber',
                                    \App\Enums\RiskLevel::Low => 'zinc',
                                }">{{ $article->risk_level->label() }}</flux:badge>
                            </div>
                        </div>
                        <flux:text size="sm" class="mt-1 text-ink/60 dark:text-zinc-400">
                            {{ $article->category->label() }} · {{ $article->jurisdiction }}
                        </flux:text>
                        @if ($article->source_summary)
                            <flux:text size="sm" class="mt-1">{{ $article->source_summary }}</flux:text>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif

        <div class="mt-10">
            <x-advisory-disclaimer />
        </div>
    </section>
</x-layouts::app>
