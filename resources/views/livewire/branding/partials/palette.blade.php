<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <div class="flex items-start justify-between gap-3">
        <div>
            <flux:heading size="sm">{{ __('Color palette') }}</flux:heading>
            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('Dominant colors carry the brand; supporting accents appear sparingly. Tap a color to save it as your primary.') }}</flux:text>
        </div>
        <flux:tooltip :content="__('Regenerate this section')">
            <flux:button size="sm" variant="ghost" icon="arrow-path" :aria-label="__('Regenerate color palette')" wire:click="regenerateSection('color_palette')" wire:loading.attr="disabled" />
        </flux:tooltip>
    </div>
    @if ($kit->color_palette === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('No usable colors came back for this version. Regenerate this section to fill it in.') }}
        </flux:text>
    @else
        @php
            $palette = collect($kit->color_palette)->map(fn (array $color, int $index): array => [...$color, 'index' => $index]);
            $dominant = $palette->filter(fn (array $color): bool => ($color['prominence'] ?? null) === 'dominant')->values();
            $supporting = $palette->filter(fn (array $color): bool => ($color['prominence'] ?? null) !== 'dominant')->values();

            if ($dominant->isEmpty()) {
                $dominant = $palette->values();
                $supporting = collect();
            }
        @endphp

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($dominant as $color)
                @php $isPicked = ($preferences['color'] ?? null) === $color['hex']; @endphp
                <button
                    type="button"
                    wire:key="color-{{ $kit->id }}-{{ $color['index'] }}"
                    wire:click="selectPreference('color', {{ $color['index'] }})"
                    aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                    @class([
                        'rounded-lg border p-2 text-start focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500',
                        'border-blue-500 dark:border-blue-400' => $isPicked,
                        'border-zinc-200 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-white/5' => ! $isPicked,
                    ])
                >
                    <span class="block h-20 rounded-md inset-ring inset-ring-black/10 dark:inset-ring-white/10" style="background-color: {{ $color['hex'] }}"></span>
                    <span class="mt-2 flex items-center gap-1">
                        @if ($isPicked)
                            <flux:icon.check class="size-4 shrink-0 text-blue-600 dark:text-blue-400" />
                        @endif
                        <span class="min-w-0 truncate text-base/7 font-medium text-zinc-900 dark:text-zinc-100 sm:text-sm/6">{{ $color['name'] }}</span>
                    </span>
                    <span class="block text-base/7 text-zinc-500 uppercase dark:text-zinc-400 sm:text-sm/6">{{ $color['hex'] }}</span>
                    @if (($color['role'] ?? '') !== '')
                        <span class="mt-1 block text-base/7 text-zinc-500 dark:text-zinc-400 sm:text-sm/6">{{ $color['role'] }}</span>
                    @endif
                    @if ($color['usage'] !== '')
                        <span class="mt-1 block text-base/7 text-pretty text-zinc-500 dark:text-zinc-400 sm:text-sm/6">{{ $color['usage'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($supporting->isNotEmpty())
            <flux:text size="sm" variant="strong" class="mt-5">{{ __('Supporting accents') }}</flux:text>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($supporting as $color)
                    @php $isPicked = ($preferences['color'] ?? null) === $color['hex']; @endphp
                    <button
                        type="button"
                        wire:key="color-{{ $kit->id }}-{{ $color['index'] }}"
                        wire:click="selectPreference('color', {{ $color['index'] }})"
                        aria-pressed="{{ $isPicked ? 'true' : 'false' }}"
                        @class([
                            'inline-flex items-center gap-2 rounded-full border py-1.5 pr-3 pl-1.5 text-base/7 sm:text-sm/6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500',
                            'border-blue-500 bg-blue-50 text-blue-900 dark:border-blue-400 dark:bg-blue-500/10 dark:text-blue-200' => $isPicked,
                            'border-zinc-200 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-white/5' => ! $isPicked,
                        ])
                    >
                        <span class="size-5 shrink-0 rounded-full inset-ring inset-ring-black/10 dark:inset-ring-white/10" style="background-color: {{ $color['hex'] }}"></span>
                        {{ $color['name'] }}
                        <span class="text-zinc-500 uppercase dark:text-zinc-400">{{ $color['hex'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    @endif
</div>
