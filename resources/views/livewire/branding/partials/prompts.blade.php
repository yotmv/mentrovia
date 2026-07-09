<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <div>
            <flux:heading size="sm">{{ __('Logo and image prompts') }}</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Paste these into an image generator or a Photo Studio project.') }}</flux:text>
        </div>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate image prompts')" wire:click="regenerateSection('image_prompts')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    @if ($kit->image_prompts === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Regenerate it to fill it in.') }}
        </flux:text>
    @endif
    <div class="mt-3 divide-y divide-zinc-950/5 dark:divide-white/10">
        @foreach ($kit->image_prompts as $index => $prompt)
            <div class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" wire:key="prompt-{{ $kit->id }}-{{ $index }}">
                <p class="min-w-0 text-base/7 text-pretty text-zinc-700 dark:text-zinc-300 sm:text-sm/6">{{ $prompt }}</p>
                <flux:button
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon="clipboard"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($prompt)); copied = true; setTimeout(() => copied = false, 1500)"
                >
                    <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                </flux:button>
            </div>
        @endforeach
    </div>
</div>
