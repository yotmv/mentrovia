<section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Advisor Q&A') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Ask about your Texas business setup, tasks, and compliance questions using your company profile and cached Mentrovia knowledge.') }}
            </flux:text>
        </div>

        <flux:button variant="ghost" size="sm" :href="route('business.intake')" wire:navigate icon="pencil-square">
            {{ $this->business === null ? __('Create profile') : __('Edit profile') }}
        </flux:button>
    </div>

    @if ($this->business === null)
        <div class="flex min-h-80 items-center justify-center">
            <div class="max-w-lg text-center">
                <flux:heading size="lg">{{ __('No company profile yet') }}</flux:heading>
                <flux:text class="mt-3">
                    {{ __('Advisor answers are scoped to your company profile so the guidance can account for your Texas location, entity type, sales tax exposure, employees, and contractors.') }}
                </flux:text>
                <flux:button variant="primary" :href="route('business.intake')" wire:navigate class="mt-6">
                    {{ __('Tell us about your business') }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="space-y-6">
                <form wire:submit="ask" class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:textarea
                        wire:model="question"
                        name="question"
                        :label="__('Question')"
                        :placeholder="__('e.g. What should I do before hiring my first employee?')"
                        rows="4"
                    />

                    <div class="mt-4 flex items-center justify-between gap-4">
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Answers use cached sources and may trigger validation for stale or high-risk topics.') }}
                        </flux:text>
                        <flux:button type="submit" variant="primary" icon="sparkles" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="ask">{{ __('Ask Advisor') }}</span>
                            <span wire:loading wire:target="ask">{{ __('Working...') }}</span>
                        </flux:button>
                    </div>
                </form>

                @error('question')
                    <flux:text size="sm" class="text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                @if ($this->conversationMessages->isEmpty())
                    <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Ask your first question') }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('Try a question about sales tax, formation, bookkeeping, payroll, contractors, or recurring compliance tasks.') }}
                        </flux:text>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach ($this->conversationMessages as $message)
                            @php
                                $answer = $message->meta['answer'] ?? null;
                            @endphp

                            <article
                                class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700"
                                wire:key="advisor-message-{{ $message->id }}"
                            >
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                                    <flux:badge size="sm" :color="$message->role === 'assistant' ? 'blue' : 'zinc'">
                                        {{ $message->role === 'assistant' ? __('Advisor') : __('You') }}
                                    </flux:badge>
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                        {{ $message->created_at?->format('M j, g:i A') }}
                                    </flux:text>
                                </div>

                                <flux:text class="text-pretty">{{ $message->content }}</flux:text>

                                @if (is_array($answer))
                                    @if (! empty($answer['checklist']))
                                        <div class="mt-5">
                                            <flux:text size="sm" variant="strong">{{ __('Checklist') }}</flux:text>
                                            <ul class="mt-2 space-y-2" role="list">
                                                @foreach ($answer['checklist'] as $item)
                                                    <li class="text-base/7 text-zinc-700 dark:text-zinc-300 sm:text-sm/6">
                                                        {{ $item }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    @if (! empty($answer['caveats']) || ! empty($answer['professional_review_flags']))
                                        <div class="mt-5 rounded-lg bg-amber-50 p-4 dark:bg-amber-900/20">
                                            <flux:text size="sm" variant="strong" class="text-amber-800 dark:text-amber-200">
                                                {{ __('Caveats') }}
                                            </flux:text>
                                            <ul class="mt-2 space-y-2" role="list">
                                                @foreach (array_merge($answer['caveats'] ?? [], $answer['professional_review_flags'] ?? []) as $item)
                                                    <li class="text-base/7 text-amber-800 dark:text-amber-200 sm:text-sm/6">
                                                        {{ $item }}
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                                        <div>
                                            <flux:text size="sm" variant="strong">{{ __('Confidence') }}</flux:text>
                                            <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                                                {{ isset($answer['confidence']) ? __(':score%', ['score' => $answer['confidence']]) : __('Not provided') }}
                                            </flux:text>
                                        </div>

                                        <div>
                                            <flux:text size="sm" variant="strong">{{ __('Source freshness') }}</flux:text>
                                            @if (empty($answer['source_freshness']))
                                                <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                                                    {{ __('No source matched.') }}
                                                </flux:text>
                                            @else
                                                <div class="mt-2 space-y-2">
                                                    @foreach ($answer['source_freshness'] as $source)
                                                        <div wire:key="advisor-source-{{ $message->id }}-{{ $source['article_id'] ?? $loop->index }}">
                                                            <flux:link
                                                                :href="route('knowledge.articles.show', $source['slug'])"
                                                                wire:navigate
                                                                class="text-sm"
                                                            >
                                                                {{ $source['title'] }}
                                                            </flux:link>
                                                            <div class="mt-1 flex flex-wrap gap-1">
                                                                <flux:badge size="sm" color="zinc">{{ $source['risk_level'] }}</flux:badge>
                                                                <flux:badge size="sm" :color="match ($source['freshness_status']) {
                                                                    'fresh' => 'green',
                                                                    'review_soon' => 'amber',
                                                                    'stale', 'missing_sources' => 'red',
                                                                    default => 'zinc',
                                                                }">{{ $source['freshness_label'] }}</flux:badge>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif
            </div>

            <aside class="space-y-4">
                <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                    <flux:heading size="sm">{{ $this->business->displayName() }}</flux:heading>
                    <dl class="mt-4 space-y-3">
                        <div>
                            <dt class="text-base/7 font-medium text-zinc-500 dark:text-zinc-400 sm:text-sm/6">{{ __('Location') }}</dt>
                            <dd class="text-base/7 text-zinc-900 dark:text-zinc-100 sm:text-sm/6">{{ $this->business->city }}, {{ $this->business->county }} {{ __('County') }}</dd>
                        </div>
                        <div>
                            <dt class="text-base/7 font-medium text-zinc-500 dark:text-zinc-400 sm:text-sm/6">{{ __('Structure') }}</dt>
                            <dd class="text-base/7 text-zinc-900 dark:text-zinc-100 sm:text-sm/6">{{ $this->business->legal_structure->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-base/7 font-medium text-zinc-500 dark:text-zinc-400 sm:text-sm/6">{{ __('Employees') }}</dt>
                            <dd class="text-base/7 tabular-nums text-zinc-900 dark:text-zinc-100 sm:text-sm/6">{{ $this->business->employee_count }}</dd>
                        </div>
                    </dl>
                </div>

                <x-advisory-disclaimer />
            </aside>
        </div>
    @endif
</section>
