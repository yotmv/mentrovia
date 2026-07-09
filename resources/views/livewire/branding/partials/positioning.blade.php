<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <flux:heading size="sm">{{ __('Positioning') }}</flux:heading>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate positioning')" wire:click="regenerateSection('positioning')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    @if ($kit->positioning !== null)
        <flux:text class="mt-3 max-w-prose text-pretty">{{ $kit->positioning }}</flux:text>
    @else
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('No positioning statement came back for this version. Regenerate this section to fill it in.') }}
        </flux:text>
    @endif
</div>
