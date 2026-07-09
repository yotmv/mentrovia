@if ($preferences !== [])
    <div class="flex flex-wrap items-center gap-2 rounded-lg bg-zinc-50 p-4 dark:bg-white/5">
        <flux:text size="sm" variant="strong">{{ __('Your picks:') }}</flux:text>
        @isset($preferences['name'])
            <flux:badge size="sm" color="blue">{{ $preferences['name'] }}</flux:badge>
        @endisset
        @isset($preferences['tagline'])
            <flux:badge size="sm" color="blue">{{ $preferences['tagline'] }}</flux:badge>
        @endisset
        @isset($preferences['color'])
            <flux:badge size="sm" color="blue">
                <span class="mr-1 inline-block size-3 rounded-full align-middle inset-ring inset-ring-black/10" style="background-color: {{ $preferences['color'] }}"></span>
                {{ $preferences['color'] }}
            </flux:badge>
        @endisset
        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Saved automatically.') }}</flux:text>
    </div>
@endif
