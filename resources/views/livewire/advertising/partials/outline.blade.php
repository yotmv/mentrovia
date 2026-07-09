<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="sm">{{ __('Landing page outline') }}</flux:heading>
    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('One simple page, top to bottom.') }}</flux:text>
    @if ($kit->landing_page_outline === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Generate a new version to fill it in.') }}
        </flux:text>
    @endif
    <ol role="list" class="mt-3 divide-y divide-zinc-950/5 dark:divide-white/10">
        @foreach ($kit->landing_page_outline as $index => $item)
            <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0" wire:key="outline-{{ $kit->id }}-{{ $index }}">
                <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-medium tabular-nums text-zinc-600 dark:bg-white/10 dark:text-zinc-300">{{ $index + 1 }}</span>
                <div class="min-w-0">
                    <flux:text size="sm" variant="strong">{{ $item['section'] }}</flux:text>
                    @if ($item['content'] !== '')
                        <p class="mt-0.5 text-base/7 text-pretty text-zinc-700 dark:text-zinc-300 sm:text-sm/6">{{ $item['content'] }}</p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
