<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <div>
            <flux:heading size="sm">{{ __('Tagline options') }}</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Tap a tagline to save it as your pick.') }}</flux:text>
        </div>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate tagline options')" wire:click="regenerateSection('tagline_options')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    <div class="mt-4 space-y-2">
        @foreach ($kit->tagline_options as $index => $tagline)
            @php $isPicked = ($preferences['tagline'] ?? null) === $tagline; @endphp
            <button
                type="button"
                wire:key="tagline-{{ $kit->id }}-{{ $index }}"
                wire:click="selectPreference('tagline', {{ $index }})"
                aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                @class([
                    'flex w-full items-start gap-2 rounded-lg border p-3 text-start text-base/7 sm:text-sm/6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500',
                    'border-blue-500 bg-blue-50 text-blue-900 dark:border-blue-400 dark:bg-blue-500/10 dark:text-blue-200' => $isPicked,
                    'border-zinc-200 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-white/5' => ! $isPicked,
                ])
            >
                @if ($isPicked)
                    <span class="flex h-lh items-center"><flux:icon.check class="size-4 shrink-0" /></span>
                @endif
                {{ $tagline }}
            </button>
        @endforeach
    </div>
</div>
