<x-layouts::app :title="__('Guides')">
    <section class="mx-auto max-w-6xl">
        <div class="border-b border-ink/10 pb-8 dark:border-white/10">
            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Guides') }}</p>
            <h1 class="mt-4 max-w-[24ch] font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ __('Practical playbooks for the work in front of you.') }}</h1>
            <p class="mt-5 max-w-[52ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Each guide brings your profile, relevant tasks, source-backed knowledge, and a clear professional-review boundary into one place.') }}</p>
        </div>

        <dl class="mt-8 grid gap-x-8 @container sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($guides as $guide)
                <div class="border-t border-ink/10 py-6 dark:border-white/10">
                    <dt class="font-display text-3xl tracking-tight text-ink dark:text-white">{{ $guide->label() }}</dt>
                    <dd class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $guide->summary() }}</dd>
                    <a href="{{ route('guides.show', $guide) }}" wire:navigate class="mt-5 inline-block text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ __('Open guide') }}</a>
                </div>
            @endforeach
        </dl>
    </section>
</x-layouts::app>
