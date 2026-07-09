<x-layouts::app :title="__('Owner pay')">
    <section class="w-full">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Paying yourself') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('How to take money out of :name, tailored to your legal and tax structure.', ['name' => $business->displayName()]) }}
                </flux:text>
            </div>
            <flux:button variant="ghost" size="sm" :href="route('business.intake')" wire:navigate icon="pencil-square">
                {{ __('Edit profile') }}
            </flux:button>
        </div>

        @if ($advice->needsStructureDecision)
            <flux:callout icon="exclamation-triangle" variant="warning" class="mb-8">
                <flux:callout.heading>{{ __('Decide on a legal structure first') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Your profile has no settled legal structure, and how you pay yourself depends almost entirely on it. Update your company profile once you decide, or review the decision with an attorney or CPA first.') }}
                    <flux:link :href="route('business.intake')" wire:navigate>{{ __('Update your company profile') }}</flux:link>
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="mb-8 rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="sm">{{ __('Your structure') }}</flux:heading>
                <flux:badge size="sm" :color="$advice->needsStructureDecision ? 'amber' : 'blue'">
                    {{ $business->legal_structure->label() }}
                </flux:badge>
            </div>
            <flux:text size="sm" class="mt-2">{{ $advice->structureSummary }}</flux:text>
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">
                {{ $advice->needsStructureDecision ? __('The methods, at a glance') : __('Methods that fit your setup') }}
            </flux:heading>

            @foreach ($advice->availableOptions() as $option)
                <article class="rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading size="sm">{{ $option->method->label() }}</flux:heading>
                        <flux:badge size="sm" :color="match ($option->fit) {
                            \App\Enums\OwnerPayFit::Typical => 'green',
                            \App\Enums\OwnerPayFit::Available => 'blue',
                            \App\Enums\OwnerPayFit::DependsOnStructure => 'amber',
                            \App\Enums\OwnerPayFit::NotAvailable => 'zinc',
                        }">{{ $option->fit->label() }}</flux:badge>
                    </div>
                    <flux:text size="sm" class="mt-2">{{ $option->summary }}</flux:text>
                    @if ($option->caveats !== [])
                        <flux:text size="sm" variant="strong" class="mt-3">{{ __('Watch out for') }}</flux:text>
                        <ul role="list" class="mt-1 space-y-1">
                            @foreach ($option->caveats as $caveat)
                                <li class="flex gap-2 text-sm">
                                    <flux:icon.exclamation-circle class="mt-0.5 size-4 shrink-0 text-gold dark:text-gold" />
                                    <flux:text size="sm">{{ $caveat }}</flux:text>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach
        </div>

        @if ($advice->unavailableOptions() !== [])
            <div class="mt-10">
                <flux:heading size="lg">{{ __('Not options for your setup') }}</flux:heading>
                <div class="mt-3 space-y-3">
                    @foreach ($advice->unavailableOptions() as $option)
                        <div class="rounded-2xl p-4 opacity-60 ring-1 ring-ink/10 dark:ring-white/10">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:heading size="sm">{{ $option->method->label() }}</flux:heading>
                                <flux:badge size="sm" color="zinc">{{ $option->fit->label() }}</flux:badge>
                            </div>
                            <flux:text size="sm" class="mt-1">{{ $option->summary }}</flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mt-10 grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
                <flux:heading size="sm">{{ __('Questions for your CPA') }}</flux:heading>
                <flux:text size="sm" class="mt-2 text-ink/60 dark:text-zinc-400">
                    {{ __('Bring these to your next conversation; the answers depend on numbers only a professional should sign off on.') }}
                </flux:text>
                <ol role="list" class="mt-3 space-y-2">
                    @foreach ($advice->cpaQuestions as $question)
                        <li class="flex gap-2 text-sm">
                            <flux:icon.chat-bubble-left-right class="mt-0.5 size-4 shrink-0 text-ink/40 dark:text-zinc-500" />
                            <flux:text size="sm">{{ $question }}</flux:text>
                        </li>
                    @endforeach
                </ol>
            </div>

            @if ($articles->isNotEmpty())
                <div class="rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
                    <flux:heading size="sm">{{ __('Related knowledge') }}</flux:heading>
                    <flux:text size="sm" class="mt-2 text-ink/60 dark:text-zinc-400">
                        {{ __('The source articles behind this guide. Open one for freshness and source details.') }}
                    </flux:text>
                    <ul role="list" class="mt-3 space-y-2">
                        @foreach ($articles as $article)
                            <li class="flex items-start gap-2">
                                <flux:icon.book-open-text class="mt-0.5 size-4 shrink-0 text-ink/40 dark:text-zinc-500" />
                                <flux:link :href="route('knowledge.articles.show', $article->slug)" wire:navigate class="text-sm">
                                    {{ $article->title }}
                                </flux:link>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="mt-10">
            <x-advisory-disclaimer />
        </div>
    </section>
</x-layouts::app>
