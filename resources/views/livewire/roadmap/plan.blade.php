<section class="w-full" aria-labelledby="roadmap-heading">
    <div class="mb-8">
        <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Plan') }}</p>
        <h1 id="roadmap-heading" class="mt-2 font-display text-4xl tracking-tight text-balance text-ink sm:text-5xl dark:text-white">
            {{ __('Your executable roadmap') }}
        </h1>
        <flux:text class="mt-2 max-w-3xl">
            {{ __('Setup and compliance work for :name. Profile signals update from company intake; execution status, owners, notes, targets, and evidence are controlled by your team.', ['name' => $this->business->displayName()]) }}
        </flux:text>
        <div class="mt-4 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-400/20 dark:bg-amber-950/30 dark:text-amber-100">
            {{ __('Planning targets are internal coordination dates. They are not filing, tax, payroll, legal, or regulatory deadlines.') }}
        </div>
    </div>

    <nav aria-label="{{ __('Roadmap focus') }}" class="mb-8 flex gap-2 overflow-x-auto pb-1">
        @foreach (['all' => __('All'), 'now' => __('Now'), 'next' => __('Next'), 'later' => __('Later')] as $value => $label)
            <flux:button
                size="sm"
                :variant="$focus === $value ? 'primary' : 'ghost'"
                :aria-pressed="$focus === $value ? 'true' : 'false'"
                wire:click="setFocus('{{ $value }}')"
                wire:loading.attr="disabled"
            >
                {{ $label }}
            </flux:button>
        @endforeach
    </nav>

    <div wire:loading.delay class="mb-4 rounded-xl bg-mist px-4 py-3 text-sm text-muted dark:bg-white/5 dark:text-zinc-300" role="status" aria-live="polite">
        {{ __('Updating roadmap…') }}
    </div>

    <flux:error name="executionStatus" />
    <flux:error name="assignedUserId" />

    <div class="space-y-10" wire:loading.class="opacity-60">
        @if ($groupedItems->isEmpty())
            <div class="rounded-2xl border border-dashed border-ink/20 p-8 text-center dark:border-white/20">
                <flux:heading size="lg">{{ __('No roadmap items match this focus') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Choose All to see completed work and every planning phase.') }}</flux:text>
                <flux:button class="mt-4" size="sm" wire:click="setFocus('all')">{{ __('Show all') }}</flux:button>
            </div>
        @else
            @foreach ($phases as $phase)
                @php
                    $phaseItems = $groupedItems->get($phase->value);
                @endphp
                @if ($phaseItems?->isNotEmpty())
                <section class="border-t border-ink/10 pt-5 dark:border-white/10" aria-labelledby="phase-{{ $phase->value }}">
                    <flux:heading id="phase-{{ $phase->value }}" size="lg">{{ $phase->label() }}</flux:heading>

                    <div class="mt-4 grid gap-4 xl:grid-cols-2">
                        @foreach ($phaseItems as $item)
                            @php
                                $template = $templates->get($item->template_key);
                                $blockers = $item->dependencies
                                    ->map->dependsOn
                                    ->filter(fn ($dependency) => $dependency?->is_active
                                        && ! in_array($dependency->execution_status, [
                                            \App\Enums\RoadmapExecutionStatus::Complete,
                                            \App\Enums\RoadmapExecutionStatus::NotApplicable,
                                        ], true));
                                $isOverdue = $item->due_on?->isBefore(today())
                                    && $item->execution_status->isOpen();
                            @endphp

                            <article
                                wire:key="roadmap-item-{{ $item->id }}"
                                @class([
                                    'rounded-2xl bg-white p-4 shadow-sm ring-1 ring-ink/10 sm:p-5 dark:bg-zinc-900 dark:ring-white/10',
                                    'opacity-70' => $item->execution_status === \App\Enums\RoadmapExecutionStatus::NotApplicable,
                                ])
                            >
                                <div class="flex flex-wrap items-start gap-2">
                                    <div class="min-w-0 flex-1">
                                        <flux:heading size="sm">{{ $template?->title ?? $item->title }}</flux:heading>
                                        <flux:text size="sm" class="mt-2">{{ $template?->whyItMatters ?? $item->why_it_matters }}</flux:text>
                                    </div>
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <flux:badge size="sm" :color="match ($item->priority) {
                                            \App\Enums\RoadmapPriority::Required => 'red',
                                            \App\Enums\RoadmapPriority::Recommended => 'amber',
                                            \App\Enums\RoadmapPriority::Optional => 'zinc',
                                        }">{{ $item->priority->label() }}</flux:badge>
                                        <flux:badge size="sm" :color="match ($item->computed_profile_status) {
                                            \App\Enums\RoadmapStatus::Complete => 'green',
                                            \App\Enums\RoadmapStatus::ToDo => 'blue',
                                            \App\Enums\RoadmapStatus::NeedsInfo => 'amber',
                                            \App\Enums\RoadmapStatus::NotApplicable => 'zinc',
                                        }">
                                            {{ __('Profile: :status', ['status' => $item->computed_profile_status->label()]) }}
                                        </flux:badge>
                                        <flux:badge size="sm" :color="match ($item->execution_status) {
                                            \App\Enums\RoadmapExecutionStatus::Complete => 'green',
                                            \App\Enums\RoadmapExecutionStatus::InProgress => 'blue',
                                            \App\Enums\RoadmapExecutionStatus::Blocked => 'amber',
                                            \App\Enums\RoadmapExecutionStatus::NotStarted,
                                            \App\Enums\RoadmapExecutionStatus::NotApplicable => 'zinc',
                                        }">
                                            {{ __('Execution: :status', ['status' => $item->execution_status->label()]) }}
                                        </flux:badge>
                                    </div>
                                </div>

                                @if ($blockers->isNotEmpty())
                                    <div class="mt-4 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
                                        <span class="font-medium">{{ __('Blocked by:') }}</span>
                                        {{ $blockers->map(fn ($dependency) => $templates->get($dependency->template_key)?->title ?? $dependency->title)->join(', ') }}
                                    </div>
                                @endif

                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <dt class="text-muted dark:text-zinc-400">{{ __('Assignee') }}</dt>
                                        <dd class="mt-1 font-medium text-ink dark:text-white">{{ $item->assignee?->name ?? __('Unassigned') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted dark:text-zinc-400">{{ __('Planning target') }}</dt>
                                        <dd @class(['mt-1 font-medium', 'text-red-700 dark:text-red-300' => $isOverdue, 'text-ink dark:text-white' => ! $isOverdue])>
                                            {{ $item->due_on?->format('M j, Y') ?? __('Not set') }}
                                            @if ($isOverdue)<span class="sr-only">{{ __('Overdue') }}</span>@endif
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-muted dark:text-zinc-400">{{ __('Evidence') }}</dt>
                                        <dd class="mt-1 font-medium text-ink dark:text-white">{{ trans_choice(':count reference|:count references', $item->evidence_count, ['count' => $item->evidence_count]) }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    @if ($item->execution_status === \App\Enums\RoadmapExecutionStatus::Complete)
                                        <flux:button size="sm" variant="ghost" wire:click="reopen({{ $item->id }})" wire:loading.attr="disabled">
                                            {{ __('Reopen') }}
                                        </flux:button>
                                    @else
                                        <flux:button size="sm" variant="primary" wire:click="markComplete({{ $item->id }})" wire:loading.attr="disabled" :disabled="$blockers->isNotEmpty()">
                                            {{ __('Mark complete') }}
                                        </flux:button>
                                    @endif

                                    @if ($template?->href !== null)
                                        <flux:button size="sm" variant="ghost" :href="$template->href" wire:navigate>
                                            {{ $template->hrefLabel ?? __('Open module') }}
                                        </flux:button>
                                    @endif
                                </div>

                                <details class="mt-4 border-t border-ink/10 pt-4 dark:border-white/10">
                                    <summary class="cursor-pointer text-sm font-medium text-moss dark:text-sage">{{ __('Edit execution details') }}</summary>
                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <flux:select wire:model="assignedUserIds.{{ $item->id }}" :label="__('Workspace assignee')">
                                            <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                                            @foreach ($this->members as $member)
                                                <flux:select.option :value="$member->id">{{ $member->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:input wire:model="dueDates.{{ $item->id }}" type="date" :label="__('Internal planning target')" />
                                        <div class="sm:col-span-2">
                                            <flux:textarea wire:model="itemNotes.{{ $item->id }}" rows="3" maxlength="2000" :label="__('Team notes')" />
                                        </div>
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center gap-3">
                                        <flux:button size="sm" wire:click="saveItem({{ $item->id }})" wire:loading.attr="disabled">{{ __('Save details') }}</flux:button>
                                        <flux:error name="itemVersions.{{ $item->id }}" />
                                        <label class="text-sm text-muted dark:text-zinc-400">
                                            <span class="sr-only">{{ __('Execution status') }}</span>
                                            <select
                                                wire:change="setStatus({{ $item->id }}, $event.target.value)"
                                                class="rounded-lg border border-ink/15 bg-white px-3 py-2 text-sm text-ink dark:border-white/15 dark:bg-zinc-900 dark:text-white"
                                                aria-label="{{ __('Execution status for :item', ['item' => $template?->title ?? $item->title]) }}"
                                            >
                                                @foreach (\App\Enums\RoadmapExecutionStatus::cases() as $status)
                                                    <option value="{{ $status->value }}" @selected($item->execution_status === $status)>{{ $status->label() }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    </div>
                                </details>

                                <details class="mt-4 border-t border-ink/10 pt-4 dark:border-white/10">
                                    <summary class="cursor-pointer text-sm font-medium text-moss dark:text-sage">{{ __('Evidence references (:count)', ['count' => $item->evidence_count]) }}</summary>

                                    <ul class="mt-3 space-y-2" aria-label="{{ __('Saved evidence') }}">
                                        @foreach ($item->evidence as $evidence)
                                            <li wire:key="roadmap-evidence-{{ $evidence->id }}" class="rounded-xl bg-mist p-3 text-sm dark:bg-white/5">
                                                <div class="flex items-start gap-3">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="font-medium text-ink dark:text-white">{{ $evidence->label }}</p>
                                                        @if ($evidence->reference_url !== null)
                                                            <a class="break-all text-moss underline dark:text-sage" href="{{ $evidence->reference_url }}" target="_blank" rel="noopener noreferrer">
                                                                {{ $evidence->reference_url }}
                                                            </a>
                                                        @endif
                                                        @if ($evidence->notes !== null)<p class="mt-1 text-muted dark:text-zinc-300">{{ $evidence->notes }}</p>@endif
                                                    </div>
                                                    <flux:button size="xs" variant="ghost" wire:click="removeEvidence({{ $item->id }}, {{ $evidence->id }})" wire:confirm="{{ __('Remove this evidence reference?') }}">
                                                        {{ __('Remove') }}
                                                    </flux:button>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>

                                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                        <flux:input wire:model="evidenceLabels.{{ $item->id }}" maxlength="255" :label="__('Evidence label')" />
                                        <flux:input wire:model="evidenceUrls.{{ $item->id }}" type="url" maxlength="2048" placeholder="https://" :label="__('HTTPS reference URL (optional)')" />
                                        <div class="sm:col-span-2">
                                            <flux:textarea wire:model="evidenceNotes.{{ $item->id }}" rows="2" maxlength="2000" :label="__('Evidence notes (optional)')" />
                                        </div>
                                    </div>
                                    <flux:button class="mt-3" size="sm" variant="ghost" wire:click="addEvidence({{ $item->id }})" wire:loading.attr="disabled">
                                        {{ __('Add evidence reference') }}
                                    </flux:button>
                                </details>

                                @if (($template?->reviewer ?? $item->reviewer) !== null)
                                    <flux:text size="sm" class="mt-4 text-ink/60 dark:text-zinc-400">
                                        {{ __('Consider review with: :reviewer', ['reviewer' => $template?->reviewer ?? $item->reviewer]) }}
                                    </flux:text>
                                @endif
                            </article>
                        @endforeach
                    </div>
                </section>
                @endif
            @endforeach
        @endif
    </div>

    <div class="mt-10">
        <x-advisory-disclaimer />
    </div>
</section>
