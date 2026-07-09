<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="sm">{{ __('Ad angles') }}</flux:heading>
    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('The customer problems and offers your ads should lead with.') }}</flux:text>
    @if ($kit->ad_angles === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Generate a new version to fill it in.') }}
        </flux:text>
    @endif
    <div class="mt-3 divide-y divide-zinc-950/5 dark:divide-white/10">
        @foreach ($kit->ad_angles as $index => $angle)
            <p class="py-3 text-base/7 text-pretty text-zinc-700 first:pt-0 last:pb-0 dark:text-zinc-300 sm:text-sm/6" wire:key="angle-{{ $kit->id }}-{{ $index }}">{{ $angle }}</p>
        @endforeach
    </div>
</div>
