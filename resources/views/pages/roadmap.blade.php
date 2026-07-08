<x-layouts::app :title="__('Roadmap')">
    <section class="w-full">
        <div class="mb-8">
            <flux:heading size="xl">{{ __('Your roadmap') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Setup and compliance steps for :name, grouped by phase and derived from your company profile.', ['name' => $business->displayName()]) }}
            </flux:text>
        </div>

        <div class="space-y-10">
            @foreach ($phases as $phase)
                @php($items = $groupedItems->get($phase->value))
                @if ($items !== null)
                    <div>
                        <flux:heading size="lg">{{ $phase->label() }}</flux:heading>
                        <div class="mt-4 space-y-3">
                            @foreach ($items as $item)
                                <div
                                    @class([
                                        'rounded-lg border border-zinc-200 p-4 dark:border-zinc-700',
                                        'opacity-60' => $item->status === \App\Enums\RoadmapStatus::NotApplicable,
                                    ])
                                >
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($item->status === \App\Enums\RoadmapStatus::Complete)
                                            <flux:icon.check-circle variant="solid" class="size-5 text-green-600 dark:text-green-400" />
                                        @endif
                                        <flux:heading size="sm">{{ $item->title }}</flux:heading>
                                        <div class="ms-auto flex items-center gap-2">
                                            <flux:badge size="sm" :color="match ($item->priority) {
                                                \App\Enums\RoadmapPriority::Required => 'red',
                                                \App\Enums\RoadmapPriority::Recommended => 'amber',
                                                \App\Enums\RoadmapPriority::Optional => 'zinc',
                                            }">{{ $item->priority->label() }}</flux:badge>
                                            <flux:badge size="sm" :color="match ($item->status) {
                                                \App\Enums\RoadmapStatus::Complete => 'green',
                                                \App\Enums\RoadmapStatus::ToDo => 'blue',
                                                \App\Enums\RoadmapStatus::NeedsInfo => 'amber',
                                                \App\Enums\RoadmapStatus::NotApplicable => 'zinc',
                                            }">{{ $item->status->label() }}</flux:badge>
                                        </div>
                                    </div>
                                    <flux:text size="sm" class="mt-2">{{ $item->whyItMatters }}</flux:text>
                                    @if ($item->reviewer !== null)
                                        <flux:text size="sm" class="mt-2 text-zinc-500 dark:text-zinc-400">
                                            {{ __('Review with: :reviewer', ['reviewer' => $item->reviewer]) }}
                                        </flux:text>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </div>

        <div class="mt-10">
            <x-advisory-disclaimer />
        </div>
    </section>
</x-layouts::app>
