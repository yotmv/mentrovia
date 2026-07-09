<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <div>
            <flux:heading size="sm">{{ __('Flyer copy') }}</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Copy for a printable flyer or door hanger.') }}</flux:text>
        </div>
        @if ($kit->flyer_copy !== null)
            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                icon="clipboard"
                class="shrink-0"
                x-data="{ copied: false }"
                x-on:click="navigator.clipboard.writeText(@js(trim($kit->flyer_copy['headline']."\n".$kit->flyer_copy['subheadline']."\n\n".implode("\n", array_map(fn (string $bullet): string => '- '.$bullet, $kit->flyer_copy['bullets']))."\n\n".$kit->flyer_copy['call_to_action']))); copied = true; setTimeout(() => copied = false, 1500)"
            >
                <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
            </flux:button>
        @endif
    </div>
    @if ($kit->flyer_copy === null)
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Generate a new version to fill it in.') }}
        </flux:text>
    @else
        <div class="mt-4 rounded-lg bg-zinc-50 p-4 dark:bg-white/5">
            <flux:heading size="lg">{{ $kit->flyer_copy['headline'] }}</flux:heading>
            @if ($kit->flyer_copy['subheadline'] !== '')
                <flux:text class="mt-1">{{ $kit->flyer_copy['subheadline'] }}</flux:text>
            @endif
            @if ($kit->flyer_copy['bullets'] !== [])
                <ul role="list" class="mt-3 space-y-1.5">
                    @foreach ($kit->flyer_copy['bullets'] as $index => $bullet)
                        <li class="flex items-start gap-2 text-base/7 text-zinc-700 dark:text-zinc-300 sm:text-sm/6" wire:key="flyer-bullet-{{ $kit->id }}-{{ $index }}">
                            <flux:icon.check class="mt-1 size-5 shrink-0 text-zinc-400 sm:size-4 dark:text-zinc-500" />
                            <span class="min-w-0">{{ $bullet }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($kit->flyer_copy['call_to_action'] !== '')
                <flux:text size="sm" variant="strong" class="mt-4">{{ $kit->flyer_copy['call_to_action'] }}</flux:text>
            @endif
        </div>
    @endif
</div>
