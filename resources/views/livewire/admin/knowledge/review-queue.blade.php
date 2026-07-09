<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Validation Review Queue') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Triage stale compliance articles and validation runs that need admin review.') }}</flux:text>
        </div>

        <flux:button variant="ghost" icon="document-text" :href="route('admin.knowledge.articles.index')" wire:navigate>
            {{ __('Knowledge admin') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap gap-3">
        <flux:input type="search" wire:model.live.debounce.300ms="search" icon="magnifying-glass"
            :placeholder="__('Search by article title...')" class="max-w-md" />

        <select wire:model.live="decisionFilter" aria-label="{{ __('Filter by final decision') }}"
            class="rounded-lg border border-zinc-200 px-3 py-2 text-base/6 dark:border-zinc-700 dark:bg-zinc-900 sm:text-sm/6">
            <option value="">{{ __('All validation decisions') }}</option>
            @foreach (\App\Enums\ValidationDecision::cases() as $decision)
                <option value="{{ $decision->value }}">{{ $decision->label() }}</option>
            @endforeach
        </select>
    </div>

    @if ($this->articles->isEmpty())
        <div class="rounded-lg border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:heading size="lg">{{ __('No review items') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Articles with stale content, failed validations, conflicting sources, or admin review decisions will appear here.') }}</flux:text>
        </div>
    @else
        <div class="-mx-4 -my-2 overflow-x-auto whitespace-nowrap sm:-mx-6 lg:-mx-8">
            <div class="inline-block min-w-full px-4 py-2 align-middle sm:px-6 lg:px-8">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-950/10 text-left dark:border-white/10">
                            <th class="py-2 pr-4 font-medium whitespace-nowrap">{{ __('Article') }}</th>
                            <th class="py-2 pr-4 font-medium whitespace-nowrap">{{ __('Review reason') }}</th>
                            <th class="py-2 pr-4 font-medium whitespace-nowrap">{{ __('Final decision') }}</th>
                            <th class="py-2 pr-4 font-medium whitespace-nowrap">{{ __('Votes') }}</th>
                            <th class="py-2 pr-4 font-medium whitespace-nowrap">{{ __('Notes') }}</th>
                            <th class="py-2 pr-4 font-medium whitespace-nowrap">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->articles as $article)
                            @php
                                $latestRun = $article->latestValidationRun;
                                $finalJudgeVote = $latestRun?->votes->firstWhere('model_role', \App\Enums\TextGenerationRole::FinalJudge);
                            @endphp

                            <tr class="border-b border-zinc-950/5 align-top dark:border-white/10" wire:key="review-article-{{ $article->id }}">
                                <td class="py-4 pr-4">
                                    <div class="min-w-64">
                                        <flux:link :href="route('admin.knowledge.articles.edit', $article)" wire:navigate>
                                            {{ $article->title }}
                                        </flux:link>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            <flux:badge size="sm" color="zinc">{{ $article->status->label() }}</flux:badge>
                                            <flux:badge size="sm" :color="$article->freshnessStatus()->color()">{{ $article->freshnessStatus()->label() }}</flux:badge>
                                            @if ($article->revalidation_requested_at)
                                                <flux:badge size="sm" color="blue">{{ __('Revalidation requested') }}</flux:badge>
                                            @endif
                                        </div>
                                        <flux:text size="sm" class="mt-2 text-zinc-500 dark:text-zinc-400">
                                            {{ __('Next review: :date', ['date' => $article->next_review_at?->format('M j, Y') ?? __('Missing')]) }}
                                        </flux:text>
                                    </div>
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="flex max-w-52 flex-wrap gap-1 whitespace-normal">
                                        @foreach ($this->reviewReasons($article) as $reason)
                                            <flux:badge size="sm" color="amber">{{ $reason }}</flux:badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="py-4 pr-4">
                                    @if ($latestRun)
                                        <div class="space-y-1">
                                            <flux:badge size="sm" :color="match ($latestRun->aggregate_decision) {
                                                \App\Enums\ValidationDecision::ApprovedCurrent => 'green',
                                                \App\Enums\ValidationDecision::ApprovedWithCaveats => 'blue',
                                                \App\Enums\ValidationDecision::NeedsSourceRefresh,
                                                \App\Enums\ValidationDecision::NeedsProfessionalReview,
                                                \App\Enums\ValidationDecision::NotEnoughInformation => 'amber',
                                                \App\Enums\ValidationDecision::ConflictingSources,
                                                \App\Enums\ValidationDecision::AdminReviewRequired => 'red',
                                                default => 'zinc',
                                            }">
                                                {{ $latestRun->aggregate_decision?->label() ?? $latestRun->status->label() }}
                                            </flux:badge>
                                            @if ($latestRun->confidence)
                                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                                    {{ __('Confidence: :score%', ['score' => $latestRun->confidence]) }}
                                                </flux:text>
                                            @endif
                                            @if ($finalJudgeVote)
                                                <flux:text size="sm" class="max-w-56 whitespace-normal text-zinc-500 dark:text-zinc-400">
                                                    {{ __('Judge: :decision', ['decision' => $finalJudgeVote->vote->label()]) }}
                                                </flux:text>
                                            @endif
                                        </div>
                                    @else
                                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('No validation run') }}</flux:text>
                                    @endif
                                </td>
                                <td class="py-4 pr-4">
                                    @if ($latestRun && $latestRun->votes->isNotEmpty())
                                        <div class="max-w-64 space-y-2 whitespace-normal">
                                            @foreach ($latestRun->votes as $vote)
                                                <div wire:key="review-vote-{{ $vote->id }}">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $vote->model_role->label() }}</span>
                                                        <flux:badge size="sm" color="zinc">{{ $vote->vote->label() }}</flux:badge>
                                                    </div>
                                                    @if ($vote->concerns)
                                                        <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                                                            {{ implode(' ', array_slice($vote->concerns, 0, 2)) }}
                                                        </flux:text>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('No votes recorded') }}</flux:text>
                                    @endif
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="w-72 whitespace-normal">
                                        @if ($article->admin_review_notes)
                                            <flux:text size="sm" class="mb-2 text-zinc-600 dark:text-zinc-300">{{ $article->admin_review_notes }}</flux:text>
                                        @endif

                                        <flux:textarea wire:model="reviewNotes.{{ $article->id }}" rows="3"
                                            :placeholder="__('Add review notes')" aria-label="{{ __('Review notes for :title', ['title' => $article->title]) }}" />

                                        @error("reviewNotes.{$article->id}")
                                            <flux:text size="sm" class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                                        @enderror

                                        <flux:button size="xs" variant="ghost" class="mt-2" wire:click="saveReviewNotes({{ $article->id }})">
                                            {{ __('Save notes') }}
                                        </flux:button>
                                    </div>
                                </td>
                                <td class="py-4 pr-4">
                                    <div class="flex flex-col items-start gap-1">
                                        <flux:button size="xs" variant="ghost" icon="check" wire:click="approveCurrent({{ $article->id }})" wire:confirm="{{ __('Approve this article as current?') }}">
                                            {{ __('Approve') }}
                                        </flux:button>
                                        <flux:button size="xs" variant="ghost" icon="exclamation-triangle" wire:click="markStale({{ $article->id }})" wire:confirm="{{ __('Mark this article stale?') }}">
                                            {{ __('Mark stale') }}
                                        </flux:button>
                                        <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="requestRevalidation({{ $article->id }})">
                                            {{ __('Revalidate') }}
                                        </flux:button>
                                        <flux:button size="xs" variant="ghost" icon="archive-box" wire:click="archive({{ $article->id }})" wire:confirm="{{ __('Archive this article?') }}">
                                            {{ __('Archive') }}
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            {{ $this->articles->links() }}
        </div>
    @endif
</div>
