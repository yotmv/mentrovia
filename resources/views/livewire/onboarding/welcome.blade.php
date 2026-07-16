<section class="mx-auto flex min-h-[calc(100dvh-8rem)] max-w-5xl items-center">
    <div class="grid w-full gap-8 @container lg:grid-cols-[14fr_10fr]">
        <div class="border-b border-ink/10 pb-8 dark:border-white/10 lg:border-b-0 lg:pb-0">
            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Texas-first business guidance') }}</p>
            <h1 class="mt-5 max-w-[20ch] font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ __('Let’s turn your next steps into a plan.') }}</h1>
            <p class="mt-6 max-w-[48ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Tell us about the business you are building or already running. Mentrovia will organize the setup work, recurring tasks, and questions that deserve a closer look.') }}</p>
            <p class="mt-3 max-w-[48ch] text-base/7 text-muted dark:text-zinc-300">{{ __('Start your company profile by choosing the path that matches where you are today.') }}</p>

            @if ($this->draft instanceof \App\Models\OnboardingDraft)
                <div class="mt-8 rounded-2xl bg-cream p-5 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10">
                    <flux:heading size="lg">{{ __('Resume your company profile') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __(':track · Step :step of :total · Saved :time', [
                            'track' => $this->draft->track->label(),
                            'step' => $this->draft->current_step,
                            'total' => $this->draft->track->stepCount(),
                            'time' => $this->draft->updated_at?->diffForHumans() ?? __('recently'),
                        ]) }}
                    </flux:text>
                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <flux:button variant="primary" :href="route('business.intake')" wire:navigate icon="arrow-right">{{ __('Resume') }}</flux:button>
                        <flux:modal.trigger name="confirm-onboarding-start-over">
                            <flux:button variant="ghost">{{ __('Start over') }}</flux:button>
                        </flux:modal.trigger>
                    </div>
                </div>

                <flux:modal name="confirm-onboarding-start-over" class="max-w-lg" focusable>
                    <form wire:submit="startOver({{ $this->draft->revision }})" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Start this profile over?') }}</flux:heading>
                            <flux:text class="mt-2">{{ __('This permanently removes the saved onboarding answers for this workspace. No company profile has been created yet.') }}</flux:text>
                        </div>
                        <flux:error name="draftRevision" />
                        <div class="flex justify-end gap-3">
                            <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button type="submit" variant="danger">{{ __('Remove saved progress') }}</flux:button>
                        </div>
                    </form>
                </flux:modal>
            @else
                <div class="mt-8 grid gap-4 sm:grid-cols-2" aria-label="{{ __('Choose your company path') }}">
                    <button type="button" wire:click="start('new_company')" wire:loading.attr="disabled" class="rounded-2xl bg-white p-5 text-left ring-1 ring-ink/10 transition hover:ring-moss focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-moss disabled:opacity-60 dark:bg-zinc-900 dark:ring-white/10 dark:hover:ring-sage">
                        <span class="block font-display text-2xl text-ink dark:text-white">{{ __('I’m starting a company') }}</span>
                        <span class="mt-2 block text-base/7 text-muted dark:text-zinc-300">{{ __('Build the foundation in five guided steps.') }}</span>
                    </button>
                    <button type="button" wire:click="start('established_company')" wire:loading.attr="disabled" class="rounded-2xl bg-white p-5 text-left ring-1 ring-ink/10 transition hover:ring-moss focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-moss disabled:opacity-60 dark:bg-zinc-900 dark:ring-white/10 dark:hover:ring-sage">
                        <span class="block font-display text-2xl text-ink dark:text-white">{{ __('I already run a company') }}</span>
                        <span class="mt-2 block text-base/7 text-muted dark:text-zinc-300">{{ __('Capture your operating baseline in three focused sections.') }}</span>
                    </button>
                </div>
                <div class="mt-3 min-h-6 text-sm text-muted dark:text-zinc-400" aria-live="polite">
                    <span wire:loading>{{ __('Starting your saved profile…') }}</span>
                </div>
            @endif
        </div>

        <aside class="rounded-3xl bg-cream p-6 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-8">
            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('What you will leave with') }}</p>
            <ol role="list" class="mt-6 divide-y divide-ink/10 dark:divide-white/10">
                <li class="py-5 first:pt-0"><p class="font-display text-2xl text-ink dark:text-white">{{ __('A clear starting point') }}</p><p class="mt-2 text-base/7 text-muted dark:text-zinc-300">{{ __('Your profile puts the right Texas-first context around the work.') }}</p></li>
                <li class="py-5"><p class="font-display text-2xl text-ink dark:text-white">{{ __('An ordered plan') }}</p><p class="mt-2 text-base/7 text-muted dark:text-zinc-300">{{ __('See what needs attention now, what can wait, and why.') }}</p></li>
                <li class="pt-5"><p class="font-display text-2xl text-ink dark:text-white">{{ __('A practical rhythm') }}</p><p class="mt-2 text-base/7 text-muted dark:text-zinc-300">{{ __('Keep recurring operational work visible as your business grows.') }}</p></li>
            </ol>
            <p class="mt-8 border-t border-ink/10 pt-5 text-base/7 text-muted dark:border-white/10 dark:text-zinc-300">{{ __('Mentrovia is educational guidance and workflow support, not legal, tax, accounting, or financial advice.') }}</p>
        </aside>
    </div>
</section>
