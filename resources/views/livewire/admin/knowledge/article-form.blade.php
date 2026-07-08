<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center gap-3">
        <flux:link :href="route('admin.knowledge.articles.index')" wire:navigate class="text-sm">
            {{ __('← Back to Knowledge Admin') }}
        </flux:link>
    </div>

    <flux:heading size="xl">{{ $article && $article->exists ? __('Edit Article') : __('New Article') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model="title" :label="__('Title')" required autofocus />

            <flux:input wire:model="slug" :label="__('Slug')" required :placeholder="__('auto-generated-from-title')" />
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <flux:input wire:model="jurisdiction" :label="__('Jurisdiction')" required />

            <select wire:model="category" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">{{ __('Choose...') }}</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                @endforeach
            </select>

            <select wire:model="risk_level" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">{{ __('Choose...') }}</option>
                @foreach ($riskLevels as $risk)
                    <option value="{{ $risk->value }}">{{ $risk->label() }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <flux:label>{{ __('Body (Markdown)') }}</flux:label>
            <textarea wire:model="body_markdown" rows="12" required
                class="mt-1 w-full rounded-lg border border-zinc-200 px-3 py-2 font-mono text-sm dark:border-zinc-700 dark:bg-zinc-900"
                placeholder="{{ __('Article body in markdown...') }}"></textarea>
        </div>

        <flux:input wire:model="source_summary" :label="__('Source Summary')" :placeholder="__('Brief description of sources used')" />

        <div class="grid gap-4 md:grid-cols-3">
            <flux:input type="date" wire:model="last_verified_at" :label="__('Last Verified')" />
            <flux:input type="date" wire:model="next_review_at" :label="__('Next Review')" />
            <flux:input type="number" wire:model="version" :label="__('Version')" required min="1" />
        </div>

        <div>
            <select wire:model="status" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">{{ __('Choose...') }}</option>
                @foreach ($statuses as $st)
                    <option value="{{ $st->value }}">{{ $st->label() }}</option>
                @endforeach
            </select>
        </div>

        @error('sources')
            <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
        @enderror

        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <div class="flex items-center justify-between">
                <flux:heading size="sm">{{ __('Sources') }}</flux:heading>
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSource">{{ __('Add source') }}</flux:button>
            </div>

            @if (empty($sources))
                <flux:text size="sm" class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('No sources added yet.') }}</flux:text>
            @else
                <div class="mt-4 space-y-4">
                    @foreach ($sources as $index => $source)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="source-{{ $index }}">
                            <div class="grid gap-3 md:grid-cols-2">
                                <flux:input wire:model="sources.{{ $index }}.source_name" :label="__('Source Name')" required />
                                <flux:input wire:model="sources.{{ $index }}.source_url" :label="__('Source URL')" required type="url" />
                            </div>
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <select wire:model="sources.{{ $index }}.source_type" class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                    @foreach ($sourceTypes as $type)
                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                    @endforeach
                                </select>
                                <flux:input type="date" wire:model="sources.{{ $index }}.retrieved_at" :label="__('Retrieved At')" />
                                <flux:input type="date" wire:model="sources.{{ $index }}.effective_date" :label="__('Effective Date')" />
                            </div>
                            <div class="mt-3 flex items-end justify-between gap-3">
                                <flux:input wire:model="sources.{{ $index }}.notes" :label="__('Notes')" class="flex-1" />
                                <flux:button size="sm" variant="ghost" color="red" icon="trash" wire:click="removeSource({{ $index }})">
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex justify-end gap-2">
            <flux:link :href="route('admin.knowledge.articles.index')" wire:navigate>
                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
            </flux:link>
            <flux:button type="submit" variant="primary">{{ __('Save article') }}</flux:button>
        </div>
    </form>
</div>
