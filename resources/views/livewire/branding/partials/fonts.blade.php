<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <flux:heading size="sm">{{ __('Typography') }}</flux:heading>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate typography notes')" wire:click="regenerateSection('font_notes')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    @if ($kit->font_notes === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Regenerate it to fill it in.') }}
        </flux:text>
    @else
        <ul class="mt-3 space-y-2" role="list">
            @foreach ($kit->font_notes as $note)
                <li class="text-base/7 text-pretty text-zinc-700 dark:text-zinc-300 sm:text-sm/6">{{ $note }}</li>
            @endforeach
        </ul>
    @endif
</div>
