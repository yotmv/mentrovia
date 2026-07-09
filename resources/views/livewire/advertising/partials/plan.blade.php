<div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
    <flux:heading size="sm">{{ __('First 30 days') }}</flux:heading>
    <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ __('A week-by-week marketing plan a busy owner can actually run.') }}</flux:text>
    @if ($kit->thirty_day_plan === [])
        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
            {{ __('Nothing usable came back for this section. Generate a new version to fill it in.') }}
        </flux:text>
    @endif
    <div class="mt-4 space-y-3">
        @foreach ($kit->thirty_day_plan as $index => $week)
            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-white/5" wire:key="week-{{ $kit->id }}-{{ $index }}">
                <flux:text size="sm" variant="strong">{{ __('Week :week: :focus', ['week' => $week['week'], 'focus' => $week['focus']]) }}</flux:text>
                @if ($week['actions'] !== [])
                    <ul role="list" class="mt-2 space-y-1">
                        @foreach ($week['actions'] as $actionIndex => $action)
                            <li class="flex items-start gap-2 text-base/7 text-zinc-700 dark:text-zinc-300 sm:text-sm/6" wire:key="week-action-{{ $kit->id }}-{{ $index }}-{{ $actionIndex }}">
                                <flux:icon.check class="mt-1 size-5 shrink-0 text-zinc-400 sm:size-4 dark:text-zinc-500" />
                                <span class="min-w-0">{{ $action }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</div>
