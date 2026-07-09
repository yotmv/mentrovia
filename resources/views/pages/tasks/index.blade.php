<x-layouts::app :title="__('Tasks')">
    <section class="w-full">
        <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Tasks') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $business === null
                        ? __('Create a company profile to generate your recurring task list.')
                        : __('Recurring operating tasks for :name.', ['name' => $business->displayName()]) }}
                </flux:text>
            </div>
            <flux:button variant="ghost" size="sm" :href="route('business.intake')" wire:navigate icon="pencil-square">
                {{ $business === null ? __('Create profile') : __('Edit profile') }}
            </flux:button>
        </div>

        @if ($business === null)
            <div class="flex min-h-80 items-center justify-center">
                <div class="max-w-lg text-center">
                    <flux:heading size="lg">{{ __('No profile yet') }}</flux:heading>
                    <flux:text class="mt-3">{{ __('Your weekly, monthly, quarterly, and yearly tasks are generated from your company profile.') }}</flux:text>
                    <flux:button variant="primary" :href="route('business.intake')" wire:navigate class="mt-6">
                        {{ __('Tell us about your business') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="mb-6 flex flex-wrap gap-2">
                @foreach ($tabs as $tab => $label)
                    <flux:button
                        size="sm"
                        :variant="$period === $tab ? 'primary' : 'ghost'"
                        :href="route('tasks.index', ['period' => $tab])"
                        wire:navigate
                    >
                        {{ __($label) }}
                    </flux:button>
                @endforeach
            </div>

            @if ($tasks->isEmpty())
                <div class="rounded-2xl p-6 ring-1 ring-ink/10 dark:ring-white/10">
                    <flux:heading size="sm">{{ __('No tasks in this view') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Try another timeframe or update your company profile to refresh applicable tasks.') }}</flux:text>
                </div>
            @else
                <div class="space-y-4">
                    @foreach ($tasks as $task)
                        <article class="rounded-2xl p-5 ring-1 ring-ink/10 dark:ring-white/10">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:heading size="sm">{{ $task->title }}</flux:heading>
                                        <flux:badge size="sm">{{ $task->frequency->label() }}</flux:badge>
                                        <flux:badge size="sm" :color="$task->completed_at ? 'green' : 'blue'">
                                            {{ $task->completed_at ? __('Complete') : __('Open') }}
                                        </flux:badge>
                                        @if ($task->requires_professional_review)
                                            <flux:badge size="sm" color="amber">{{ __('Review') }}</flux:badge>
                                        @endif
                                    </div>
                                    <flux:text size="sm" class="mt-2">{{ $task->description }}</flux:text>
                                </div>
                                <div class="text-end">
                                    <flux:text size="sm" variant="strong">
                                        {{ $task->due_on?->format('M j, Y') ?? __('No due date') }}
                                    </flux:text>
                                    <flux:text size="sm" class="mt-1 text-ink/60 dark:text-zinc-400">
                                        {{ $task->category->label() }}
                                    </flux:text>
                                </div>
                            </div>

                            <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_18rem]">
                                <div>
                                    <flux:text size="sm" variant="strong">{{ __('Why it matters') }}</flux:text>
                                    <flux:text size="sm" class="mt-1">
                                        {{ $task->requires_professional_review
                                            ? __('This task touches tax, payroll, or compliance decisions that may need professional review.')
                                            : __('This task keeps routine business admin from piling up.') }}
                                    </flux:text>
                                    @if ($task->sourceArticle !== null)
                                        <flux:link :href="route('knowledge.articles.show', $task->sourceArticle->slug)" wire:navigate class="mt-2 inline-block text-sm">
                                            {{ __('Source: :title', ['title' => $task->sourceArticle->title]) }}
                                        </flux:link>
                                    @endif
                                </div>

                                <form method="POST" action="{{ route('tasks.update', $task) }}" class="space-y-3">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="completed" value="0">
                                    <flux:checkbox name="completed" value="1" :checked="$task->completed_at !== null" :label="__('Completed')" />
                                    <flux:textarea name="notes" :label="__('Notes')" rows="2">{{ old('notes', $task->notes) }}</flux:textarea>
                                    <flux:button type="submit" size="sm" variant="primary" icon="check">
                                        {{ __('Save') }}
                                    </flux:button>
                                </form>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        @endif
    </section>
</x-layouts::app>
