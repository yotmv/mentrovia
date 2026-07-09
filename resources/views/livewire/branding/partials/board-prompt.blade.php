<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <div>
            <flux:heading size="sm">{{ __('Brand board prompt') }}</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('One production-ready prompt for a 4K brand board: two marketing-page mockups plus a typography and color rail. Paste it into an image generator or a Photo Studio project.') }}
            </flux:text>
        </div>
        <div class="flex shrink-0 items-center gap-1">
            @if ($kit->brand_board_prompt !== null)
                <flux:button
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon="clipboard"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($kit->brand_board_prompt)); copied = true; setTimeout(() => copied = false, 1500)"
                >
                    <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                </flux:button>
            @endif
            <flux:tooltip :content="__('Regenerate this section')">
                <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate brand board prompt')" wire:click="regenerateSection('brand_board_prompt')" wire:loading.attr="disabled" />
            </flux:tooltip>
        </div>
    </div>
    @if ($kit->brand_board_prompt === null)
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Regenerate it to fill it in.') }}
        </flux:text>
    @else
        <div class="mt-3 max-h-64 overflow-y-auto rounded-lg bg-zinc-50 p-4 dark:bg-white/5">
            <p class="text-base/7 whitespace-pre-wrap text-pretty text-zinc-700 dark:text-zinc-300 sm:text-sm/6">{{ $kit->brand_board_prompt }}</p>
        </div>
    @endif
</div>
