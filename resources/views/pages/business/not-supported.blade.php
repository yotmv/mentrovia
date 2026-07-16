<x-layouts::app :title="__('Texas support')">
    <section class="mx-auto flex min-h-[calc(100dvh-8rem)] max-w-3xl items-center">
        <div class="border-s-2 border-s-gold ps-6">
            <p class="font-mono text-base font-medium tracking-wide text-gold sm:text-sm">{{ __('Current coverage') }}</p>
            <h1 class="mt-4 max-w-[22ch] font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ __('Mentrovia is Texas-first today.') }}</h1>
            <p class="mt-6 max-w-[48ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('The current guidance, source checks, and recurring workflows are built for Texas small-business operators. We do not want to present state-specific guidance where we cannot support it carefully.') }}</p>
            <div class="mt-8 flex flex-wrap gap-4">
                <flux:button variant="primary" :href="route('business.intake')" wire:navigate>{{ __('Return to your profile') }}</flux:button>
                <a href="{{ route('home') }}" class="text-base font-medium text-ink underline decoration-moss/40 underline-offset-4 sm:text-sm dark:text-white">{{ __('Return home') }}</a>
            </div>
        </div>
    </section>
</x-layouts::app>
