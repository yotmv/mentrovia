<section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Advisor history') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Recent source-aware answers saved for your account.') }}
            </flux:text>
        </div>

        <flux:button variant="ghost" size="sm" :href="route('advisor')" wire:navigate icon="sparkles">
            {{ __('Ask Advisor') }}
        </flux:button>
    </div>

    @if ($this->messages->isEmpty())
        <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
            <flux:heading size="sm">{{ __('No Advisor answers yet') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Ask a question to start building your saved Advisor history.') }}
            </flux:text>
        </div>
    @else
        <div class="space-y-4">
            @foreach ($this->messages as $message)
                @php
                    $answer = $message->meta['answer'] ?? [];
                    $feedback = $message->meta['feedback'] ?? null;
                @endphp

                <article class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700" wire:key="advisor-history-{{ $message->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <flux:badge size="sm" color="blue">{{ __('Advisor') }}</flux:badge>
                                @if (is_array($answer) && ($answer['safety_status'] ?? 'answered') !== 'answered')
                                    <flux:badge size="sm" color="amber">{{ __('Guarded') }}</flux:badge>
                                @endif
                                @if (is_array($feedback) && ($feedback['reported'] ?? false))
                                    <flux:badge size="sm" color="red">{{ __('Reported') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text class="mt-3 text-pretty">{{ $message->content }}</flux:text>
                        </div>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            {{ $message->created_at?->format('M j, Y g:i A') }}
                        </flux:text>
                    </div>

                    @if (is_array($answer) && ! empty($answer['source_freshness']))
                        <div class="mt-5 divide-y divide-zinc-950/5 border-t border-zinc-950/5 pt-4 dark:divide-white/10 dark:border-white/10">
                            @foreach ($answer['source_freshness'] as $source)
                                <div class="py-2 first:pt-0 last:pb-0" wire:key="advisor-history-source-{{ $message->id }}-{{ $source['article_id'] ?? $loop->index }}">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:link :href="route('knowledge.articles.show', $source['slug'])" wire:navigate class="text-sm">
                                            {{ $source['title'] }}
                                        </flux:link>
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
                </article>
            @endforeach
        </div>
    @endif
</section>
