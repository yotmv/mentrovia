<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="sm">{{ __('Social posts') }}</flux:heading>
    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Organic posts you can publish as-is or tweak.') }}</flux:text>
    @if ($kit->social_posts === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Generate a new version to fill it in.') }}
        </flux:text>
    @endif
    <div class="mt-3 divide-y divide-zinc-950/5 dark:divide-white/10">
        @foreach ($kit->social_posts as $index => $post)
            <div class="flex items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" wire:key="post-{{ $kit->id }}-{{ $index }}">
                <p class="min-w-0 text-base/7 text-pretty text-zinc-700 dark:text-zinc-300 sm:text-sm/6">{{ $post }}</p>
                <flux:button
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon="clipboard"
                    x-data="{ copied: false }"
                    x-on:click="navigator.clipboard.writeText(@js($post)); copied = true; setTimeout(() => copied = false, 1500)"
                >
                    <span x-text="copied ? '{{ __('Copied') }}' : '{{ __('Copy') }}'">{{ __('Copy') }}</span>
                </flux:button>
            </div>
        @endforeach
    </div>
</div>
