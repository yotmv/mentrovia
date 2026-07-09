<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="sm">{{ __('Facebook and Instagram ads') }}</flux:heading>
    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Ready-to-adapt ad copy with a headline, body, and call to action.') }}</flux:text>
    @if ($kit->facebook_instagram_copy === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Generate a new version to fill it in.') }}
        </flux:text>
    @endif
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach ($kit->facebook_instagram_copy as $index => $ad)
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-white/5" wire:key="meta-ad-{{ $kit->id }}-{{ $index }}">
                <div class="flex items-start justify-between gap-3">
                    <flux:text size="sm" variant="strong" class="min-w-0">{{ $ad['headline'] }}</flux:text>
                    <flux:button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon="clipboard"
                        class="shrink-0"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText(@js(trim($ad['headline']."\n\n".$ad['body'].($ad['cta'] !== '' ? "\n\n".$ad['cta'] : '')))); copied = true; setTimeout(() => copied = false, 1500)"
                    >
                        <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                    </flux:button>
                </div>
                <p class="mt-2 text-base/7 text-pretty text-zinc-700 dark:text-zinc-300 sm:text-sm/6">{{ $ad['body'] }}</p>
                @if ($ad['cta'] !== '')
                    <flux:text size="sm" class="mt-3 font-medium">{{ $ad['cta'] }}</flux:text>
                @endif
            </div>
        @endforeach
    </div>
</div>
