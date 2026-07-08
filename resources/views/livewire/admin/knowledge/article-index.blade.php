<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Knowledge Admin') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Manage compliance articles and source metadata.') }}</flux:text>
        </div>

        <flux:button variant="primary" icon="plus" :href="route('admin.knowledge.articles.create')" wire:navigate>
            {{ __('New article') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input type="search" wire:model.live.debounce.300ms="search" icon="magnifying-glass"
            :placeholder="__('Search by title...')" class="max-w-md" />

        <select wire:model.live="statusFilter" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            <option value="">{{ __('All statuses') }}</option>
            @foreach (\App\Enums\ArticleStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>
    </div>

    @if ($this->articles->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:heading size="lg">{{ __('No articles found') }}</flux:heading>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left dark:border-zinc-700">
                        <th class="py-2 pr-4 font-medium">{{ __('Title') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Category') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Risk') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Sources') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->articles as $article)
                        <tr class="border-b border-zinc-100 dark:border-zinc-800" wire:key="article-{{ $article->id }}">
                            <td class="py-3 pr-4">
                                <flux:link :href="route('admin.knowledge.articles.edit', $article)" wire:navigate>{{ $article->title }}</flux:link>
                            </td>
                            <td class="py-3 pr-4">{{ $article->category->label() }}</td>
                            <td class="py-3 pr-4">
                                <flux:badge size="sm" :color="match ($article->risk_level) {
                                    \App\Enums\RiskLevel::High => 'red',
                                    \App\Enums\RiskLevel::Medium => 'amber',
                                    \App\Enums\RiskLevel::Low => 'zinc',
                                }">{{ $article->risk_level->label() }}</flux:badge>
                            </td>
                            <td class="py-3 pr-4">
                                <flux:badge size="sm" :color="match ($article->status) {
                                    \App\Enums\ArticleStatus::Published => 'green',
                                    \App\Enums\ArticleStatus::NeedsReview => 'amber',
                                    \App\Enums\ArticleStatus::Draft => 'zinc',
                                    \App\Enums\ArticleStatus::Archived => 'zinc',
                                }">{{ $article->status->label() }}</flux:badge>
                            </td>
                            <td class="py-3 pr-4">{{ $article->sources_count }}</td>
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-1">
                                    <flux:button size="xs" variant="ghost" wire:click="markStale({{ $article->id }})" wire:confirm="{{ __('Mark as needing review?') }}">
                                        {{ __('Mark stale') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="requestRevalidation({{ $article->id }})">
                                        {{ __('Revalidate') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="archive({{ $article->id }})" wire:confirm="{{ __('Archive this article?') }}">
                                        {{ __('Archive') }}
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div>
            {{ $this->articles->links() }}
        </div>
    @endif
</div>
