<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <div>
            <flux:heading size="sm">{{ __('Name ideas') }}</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Tap a name to save it as your pick.') }}</flux:text>
        </div>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate name ideas')" wire:click="regenerateSection('name_ideas')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($kit->name_ideas as $index => $name)
            @php $isPicked = ($preferences['name'] ?? null) === $name; @endphp
            <button
                type="button"
                wire:key="name-{{ $kit->id }}-{{ $index }}"
                wire:click="selectPreference('name', {{ $index }})"
                aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full border py-1.5 pr-3 text-base/7 sm:text-sm/6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500',
                    'border-moss bg-sage/50 pl-2 text-moss dark:border-sage dark:bg-white/10 dark:text-sage' => $isPicked,
                    'border-zinc-200 pl-3 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-white/5' => ! $isPicked,
                ])
            >
                @if ($isPicked)
                    <flux:icon.check class="size-4 shrink-0" />
                @endif
                {{ $name }}
            </button>
        @endforeach
    </div>
</div>
