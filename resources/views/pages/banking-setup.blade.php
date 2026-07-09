<x-layouts::app :title="__('Banking setup')">
    <section class="w-full">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Banking setup') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Separate accounts, reserves, and bank-visit documents for :name.', ['name' => $business->displayName()]) }}
                </flux:text>
            </div>
            <flux:button variant="ghost" size="sm" :href="route('business.intake')" wire:navigate icon="pencil-square">
                {{ __('Edit profile') }}
            </flux:button>
        </div>

        @if ($advice->warnings !== [])
            <div class="mb-8 space-y-3">
                @foreach ($advice->warnings as $warning)
                    <flux:callout icon="exclamation-triangle" variant="warning">
                        <flux:callout.heading>{{ __('Check before you go') }}</flux:callout.heading>
                        <flux:callout.text>{{ $warning }}</flux:callout.text>
                    </flux:callout>
                @endforeach
            </div>
        @endif

        <div class="mb-8 rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="sm">{{ __('Checklist progress') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                        {{ __('Mark setup steps as done after you complete them. The dedicated checking item also updates your profile risk flags.') }}
                    </flux:text>
                </div>
                <flux:badge size="sm" :color="$advice->completedCount() === $advice->checklistCount() ? 'green' : 'blue'">
                    {{ __(':done of :total done', ['done' => $advice->completedCount(), 'total' => $advice->checklistCount()]) }}
                </flux:badge>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-[1fr_22rem]">
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Banking checklist') }}</flux:heading>

                @foreach ($advice->checklist as $item)
                    <article class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($item->completed)
                                        <flux:icon.check-circle variant="solid" class="size-5 shrink-0 text-green-600 dark:text-green-400" />
                                    @endif
                                    <flux:heading size="sm">{{ $item->title }}</flux:heading>
                                    <flux:badge size="sm" :color="$item->completed ? 'green' : ($item->recommended ? 'blue' : 'amber')">
                                        {{ $item->completed ? __('Complete') : ($item->recommended ? __('Recommended') : __('Confirm first')) }}
                                    </flux:badge>
                                </div>
                                <flux:text size="sm" class="mt-2">{{ $item->description }}</flux:text>
                            </div>

                            <form method="POST" action="{{ route('banking-setup.items.update', ['key' => $item->key]) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="completed" value="{{ $item->completed ? '0' : '1' }}">
                                <flux:button type="submit" size="sm" variant="ghost" :icon="$item->completed ? 'arrow-uturn-left' : 'check'">
                                    {{ $item->completed ? __('Undo') : __('Mark done') }}
                                </flux:button>
                            </form>
                        </div>
                    </article>
                @endforeach
            </div>

            <aside class="space-y-6">
                <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('What to bring to the bank') }}</flux:heading>
                    <div class="mt-4 space-y-4">
                        @foreach ($advice->documents as $document)
                            <div>
                                <div class="flex items-start gap-2">
                                    @if ($document->ready)
                                        <flux:icon.check-circle variant="solid" class="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400" />
                                    @else
                                        <flux:icon.exclamation-circle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                    @endif
                                    <div class="min-w-0">
                                        <flux:text size="sm" variant="strong">{{ $document->title }}</flux:text>
                                        @if ($document->status !== null)
                                            <flux:badge size="sm" :color="$document->ready ? 'green' : 'amber'" class="mt-1">
                                                {{ $document->status }}
                                            </flux:badge>
                                        @endif
                                    </div>
                                </div>
                                <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                                    {{ $document->description }}
                                </flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($articles->isNotEmpty())
                    <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Related knowledge') }}</flux:heading>
                        <flux:text size="sm" class="mt-2 text-zinc-500 dark:text-zinc-400">
                            {{ __('Open the source articles for freshness and source details.') }}
                        </flux:text>
                        <ul role="list" class="mt-3 space-y-2">
                            @foreach ($articles as $article)
                                <li class="flex items-start gap-2">
                                    <flux:icon.book-open-text class="mt-0.5 size-4 shrink-0 text-zinc-400" />
                                    <flux:link :href="route('knowledge.articles.show', $article->slug)" wire:navigate class="text-sm">
                                        {{ $article->title }}
                                    </flux:link>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </aside>
        </div>

        <div class="mt-10">
            <x-advisory-disclaimer />
        </div>
    </section>
</x-layouts::app>
