<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <flux:heading size="sm">{{ __('Social bios') }}</flux:heading>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate social bios')" wire:click="regenerateSection('social_bios')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    @if ($kit->social_bios === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Regenerate it to fill it in.') }}
        </flux:text>
    @endif
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach ($kit->social_bios as $index => $bio)
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-white/5" wire:key="bio-{{ $kit->id }}-{{ $index }}">
                <div class="flex items-start justify-between gap-3">
                    <flux:text size="sm" variant="strong">{{ str_replace('_', ' ', Illuminate\Support\Str::title($bio['platform'])) }}</flux:text>
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon="clipboard"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText(@js($bio['bio'])); copied = true; setTimeout(() => copied = false, 1500)"
                    >
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                    </flux:button>
                </div>
                <flux:text size="sm" class="mt-2 text-pretty">{{ $bio['bio'] }}</flux:text>
            </div>
        @endforeach
    </div>
</div>
