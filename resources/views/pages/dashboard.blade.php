<x-layouts::app :title="__('Dashboard')">
    <section class="w-full">
        @if ($business === null)
            <div class="flex min-h-96 items-center justify-center">
                <div class="max-w-lg text-center">
                    <flux:heading size="xl">{{ __('Welcome to Mentrovia') }}</flux:heading>
                    <flux:text class="mt-4">
                        {{ __('Answer a few questions about your business and we will build your personalized Texas setup roadmap, risk flags, and task list.') }}
                    </flux:text>
                    <flux:button variant="primary" :href="route('business.intake')" wire:navigate class="mt-6">
                        {{ __('Tell us about your business') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="xl">{{ $business->displayName() }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ $business->stage?->label() }} · {{ $business->city }}, {{ $business->county }} {{ __('County') }}, TX
                    </flux:text>
                </div>
                <flux:button variant="ghost" size="sm" :href="route('business.intake')" wire:navigate icon="pencil-square">
                    {{ __('Edit profile') }}
                </flux:button>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Business setup score') }}</flux:heading>
                    <div class="mt-4 flex items-baseline gap-2">
                        <span class="text-4xl font-semibold">{{ $setupScore }}</span>
                        <flux:text>/ 100</flux:text>
                    </div>
                    <div class="mt-4 h-2 w-full rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-2 rounded-full bg-green-600 transition-all dark:bg-green-400" style="width: {{ $setupScore }}%"></div>
                    </div>
                    @if ($missingSetupItems !== [])
                        <flux:text size="sm" class="mt-4 font-medium">{{ __('Still missing:') }}</flux:text>
                        <ul class="mt-2 space-y-1">
                            @foreach ($missingSetupItems as $item)
                                <li><flux:text size="sm">• {{ $item }}</flux:text></li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:heading size="sm">{{ __('Risk flags') }}</flux:heading>
                    @if ($riskFlags === [])
                        <flux:text size="sm" class="mt-4">{{ __('No risk flags — nice work.') }}</flux:text>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($riskFlags as $flag)
                                <div>
                                    <flux:badge size="sm" color="red">{{ $flag->label() }}</flux:badge>
                                    <flux:text size="sm" class="mt-1">{{ $flag->description() }}</flux:text>
                                    @if (in_array($flag, [
                                        \App\Enums\RiskFlag::PersonalBankCommingling,
                                        \App\Enums\RiskFlag::MissingEin,
                                        \App\Enums\RiskFlag::SalesTaxPermitGap,
                                        \App\Enums\RiskFlag::EmployeesWithoutPayroll,
                                    ], true))
                                        <flux:link :href="route('banking-setup')" wire:navigate class="mt-1 inline-block text-sm">
                                            {{ __('Open banking checklist') }}
                                        </flux:link>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Next actions') }}</flux:heading>
                        <flux:link :href="route('roadmap')" wire:navigate class="text-sm">{{ __('Full roadmap') }}</flux:link>
                    </div>
                    <ol class="mt-4 space-y-3">
                        @foreach ($nextActions as $item)
                            <li class="flex items-start gap-2">
                                <flux:badge size="sm" :color="$item->priority === \App\Enums\RoadmapPriority::Required ? 'red' : 'zinc'">
                                    {{ $item->priority->label() }}
                                </flux:badge>
                                <flux:text size="sm">{{ $item->title }}</flux:text>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>

            <div class="mt-10">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:heading size="sm">{{ __('Upcoming tasks') }}</flux:heading>
                    <flux:link :href="route('tasks.index')" wire:navigate class="text-sm">{{ __('Task list') }}</flux:link>
                </div>

                @if ($upcomingTasks->isEmpty())
                    <flux:text size="sm" class="mt-4">{{ __('No open upcoming tasks. Your current generated list is clear.') }}</flux:text>
                @else
                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($upcomingTasks as $task)
                            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                <div class="flex items-center justify-between gap-3">
                                    <flux:badge size="sm">{{ $task->frequency->label() }}</flux:badge>
                                    <flux:text size="sm">{{ $task->due_on?->format('M j') }}</flux:text>
                                </div>
                                <flux:text size="sm" variant="strong" class="mt-3">{{ $task->title }}</flux:text>
                                <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">{{ $task->category->label() }}</flux:text>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="mt-10">
                <x-advisory-disclaimer />
            </div>
        @endif
    </section>
</x-layouts::app>
