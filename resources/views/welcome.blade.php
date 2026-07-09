<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @php($title = __('Your Texas Small Business Mentor'))
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-cream font-sans text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <header class="border-b border-ink/10 bg-cream/95 dark:border-white/10 dark:bg-zinc-950/95">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4 sm:px-6 lg:px-8">
                <x-app-logo href="{{ route('home') }}" aria-label="{{ __('Mentrovia homepage') }}" />

                <nav aria-label="{{ __('Primary navigation') }}" class="hidden items-center gap-7 text-sm font-medium text-muted lg:flex">
                    <a href="#how-it-works" class="hover:text-ink dark:hover:text-white">{{ __('How it works') }}</a>
                    <a href="#guidance" class="hover:text-ink dark:hover:text-white">{{ __('Guidance') }}</a>
                    <a href="#trust" class="hover:text-ink dark:hover:text-white">{{ __('Why Mentrovia') }}</a>
                </nav>

                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}" class="rounded-full border border-ink/15 px-3 py-2 text-sm font-medium text-ink hover:border-moss hover:text-moss dark:border-white/20 dark:text-white dark:hover:border-sage dark:hover:text-sage">
                            {{ __('Open dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm font-medium text-ink hover:text-moss dark:text-white dark:hover:text-sage">
                            {{ __('Log in') }}
                        </a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-full border border-ink/15 px-3 py-2 text-sm font-medium text-ink hover:border-moss hover:text-moss dark:border-white/20 dark:text-white dark:hover:border-sage dark:hover:text-sage">
                                {{ __('Get started') }}
                            </a>
                        @endif
                    @endauth
                </div>
            </div>
        </header>

        <main class="isolate overflow-hidden">
            <section class="py-16 sm:py-20 lg:py-28">
                <div class="mx-auto grid max-w-7xl items-center gap-12 px-5 sm:px-6 lg:grid-cols-[19fr_17fr] lg:px-8">
                    <div>
                        <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Texas-first business guidance') }}</p>
                        <h1 class="mt-5 max-w-[16ch] font-display text-5xl tracking-tight text-balance sm:text-6xl lg:text-7xl">
                            {{ __('A clearer path for the business you are building.') }}
                        </h1>
                        <p class="mt-6 max-w-[48ch] text-base/7 text-pretty text-muted sm:text-lg/8 dark:text-zinc-300">
                            {{ __('Mentrovia turns the unfamiliar work of starting and running a Texas business into a focused plan, with practical explanations, source-backed guidance, and a task rhythm that keeps moving.') }}
                        </p>
                        <div class="mt-8 flex flex-wrap items-center gap-4">
                            @auth
                                <a href="{{ route('dashboard') }}" class="rounded-full bg-moss px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-moss/20 ring-1 ring-moss hover:bg-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-moss dark:shadow-none">
                                    {{ __('Open your dashboard') }}
                                </a>
                            @else
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="rounded-full bg-moss px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-moss/20 ring-1 ring-moss hover:bg-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-moss dark:shadow-none">
                                        {{ __('Build your roadmap') }}
                                    </a>
                                @endif
                            @endauth
                            <a href="#how-it-works" class="text-sm font-semibold text-ink underline decoration-moss/40 underline-offset-4 hover:decoration-moss dark:text-white">
                                {{ __('See how it works') }}
                            </a>
                        </div>
                        <p class="mt-6 max-w-[70ch] text-sm/6 text-muted dark:text-zinc-400">
                            {{ __('Educational guidance and workflow support for small-business operators. It is not legal, tax, accounting, or financial advice.') }}
                        </p>
                    </div>

                    <div class="rounded-[min(2vw,1.5rem)] bg-white p-3 shadow-2xl shadow-ink/10 ring-1 ring-ink/10 dark:bg-zinc-900 dark:shadow-none dark:ring-white/10">
                        <div class="rounded-[calc(min(2vw,1.5rem)-0.75rem)] bg-paper p-4 sm:p-5 dark:bg-zinc-950">
                            <div class="flex items-center justify-between gap-4 border-b border-ink/10 pb-4 dark:border-white/10">
                                <div class="flex min-w-0 items-center gap-3">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-moss font-display text-lg text-white">M</div>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-ink dark:text-white">{{ __('Mentrovia') }}</p>
                                        <p class="text-sm text-muted dark:text-zinc-400">{{ __('Your business workspace') }}</p>
                                    </div>
                                </div>
                                <span class="rounded-full bg-sage px-3 py-1 text-sm font-medium text-moss">{{ __('On track') }}</span>
                            </div>

                            <div class="grid gap-4 py-5 @container">
                                <div class="rounded-2xl bg-white p-4 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <p class="text-sm font-medium text-muted dark:text-zinc-400">{{ __('Business setup score') }}</p>
                                            <p class="mt-2 font-display text-4xl tabular-nums text-ink dark:text-white">68<span class="font-sans text-base text-muted dark:text-zinc-400">/100</span></p>
                                        </div>
                                        <p class="text-sm font-medium text-moss dark:text-sage">{{ __('Good progress') }}</p>
                                    </div>
                                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-sage dark:bg-zinc-800">
                                        <div class="h-full w-[68%] rounded-full bg-moss"></div>
                                    </div>
                                </div>

                                <div class="grid gap-4 @md:grid-cols-2">
                                    <div class="rounded-2xl bg-white p-4 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10">
                                        <div class="flex items-start justify-between gap-3">
                                            <p class="text-sm font-medium text-ink dark:text-white">{{ __('Next milestone') }}</p>
                                            <flux:icon.map class="size-4 shrink-0 text-moss dark:text-sage" />
                                        </div>
                                        <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-400">{{ __('Set up a dedicated business bank account.') }}</p>
                                        <p class="mt-4 text-sm font-medium text-moss dark:text-sage">{{ __('Open roadmap') }}</p>
                                    </div>
                                    <div class="rounded-2xl bg-white p-4 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10">
                                        <div class="flex items-start justify-between gap-3">
                                            <p class="text-sm font-medium text-ink dark:text-white">{{ __('This week') }}</p>
                                            <flux:icon.calendar-days class="size-4 shrink-0 text-moss dark:text-sage" />
                                        </div>
                                        <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-400">{{ __('Review your sales tax registration status.') }}</p>
                                        <p class="mt-4 text-sm font-medium text-moss dark:text-sage">{{ __('View tasks') }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="guidance" class="bg-white py-16 dark:bg-zinc-900 sm:py-20">
                <div class="mx-auto max-w-7xl px-5 sm:px-6 lg:px-8">
                    <div>
                        <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('What Mentrovia organizes') }}</p>
                        <h2 class="mt-4 max-w-[24ch] font-display text-4xl tracking-tight text-balance sm:text-5xl">
                            {{ __('The practical work behind a healthy business.') }}
                        </h2>
                        <p class="mt-5 max-w-[48ch] text-base/7 text-pretty text-muted sm:text-lg/8 dark:text-zinc-300">
                            {{ __('Get a structured way to understand what matters now, what comes next, and why each step belongs in your business.') }}
                        </p>
                    </div>

                    <dl class="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="border-t border-ink/10 pt-5 dark:border-white/10">
                            <dt class="font-display text-2xl text-ink dark:text-white">{{ __('Formation and foundations') }}</dt>
                            <dd class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Move from an early idea to an organized operation with an entity, registrations, and a clear setup sequence.') }}</dd>
                        </div>
                        <div class="border-t border-ink/10 pt-5 dark:border-white/10">
                            <dt class="font-display text-2xl text-ink dark:text-white">{{ __('Taxes and compliance') }}</dt>
                            <dd class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Understand the Texas-specific permits, filings, and recurring obligations that deserve your attention.') }}</dd>
                        </div>
                        <div class="border-t border-ink/10 pt-5 dark:border-white/10">
                            <dt class="font-display text-2xl text-ink dark:text-white">{{ __('Operating rhythm') }}</dt>
                            <dd class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Keep your banking, payroll, owner pay, and recurring work visible in a single, practical task plan.') }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section id="how-it-works" class="py-16 sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-8 px-5 sm:px-6 lg:grid-cols-[17fr_19fr] lg:px-8">
                    <div>
                        <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('A straightforward starting point') }}</p>
                        <h2 class="mt-4 max-w-[24ch] font-display text-4xl tracking-tight text-balance sm:text-5xl">
                            {{ __('Start with your business as it is today.') }}
                        </h2>
                    </div>
                    <ol role="list" class="grid gap-5 sm:grid-cols-3">
                        <li class="border-t border-ink/10 pt-5 dark:border-white/10">
                            <p class="font-display text-3xl text-moss dark:text-sage">01</p>
                            <h3 class="mt-3 text-base font-semibold text-ink dark:text-white">{{ __('Tell us where you are') }}</h3>
                            <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Share the details that shape your next business decisions.') }}</p>
                        </li>
                        <li class="border-t border-ink/10 pt-5 dark:border-white/10">
                            <p class="font-display text-3xl text-moss dark:text-sage">02</p>
                            <h3 class="mt-3 text-base font-semibold text-ink dark:text-white">{{ __('Get an ordered plan') }}</h3>
                            <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('See a personalized roadmap that explains the priority behind every step.') }}</p>
                        </li>
                        <li class="border-t border-ink/10 pt-5 dark:border-white/10">
                            <p class="font-display text-3xl text-moss dark:text-sage">03</p>
                            <h3 class="mt-3 text-base font-semibold text-ink dark:text-white">{{ __('Keep your momentum') }}</h3>
                            <p class="mt-2 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Use recurring tasks and on-demand guidance to keep the work moving.') }}</p>
                        </li>
                    </ol>
                </div>
            </section>

            <section id="trust" class="bg-ink py-16 text-white sm:py-20">
                <div class="mx-auto grid max-w-7xl gap-8 px-5 sm:px-6 lg:grid-cols-[17fr_19fr] lg:px-8">
                    <div>
                        <p class="font-mono text-sm font-medium tracking-wide text-gold">{{ __('Built for careful decisions') }}</p>
                        <h2 class="mt-4 max-w-[24ch] font-display text-4xl tracking-tight text-balance sm:text-5xl">{{ __('Guidance that makes its limits clear.') }}</h2>
                    </div>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="border-t border-white/15 pt-5">
                            <h3 class="text-base font-semibold">{{ __('Source-aware by design') }}</h3>
                            <p class="mt-2 text-base/7 text-pretty text-white/70">{{ __('Mentrovia pairs practical explanations with maintained, source-backed knowledge for compliance-sensitive topics.') }}</p>
                        </div>
                        <div class="border-t border-white/15 pt-5">
                            <h3 class="text-base font-semibold">{{ __('Built to support, not replace') }}</h3>
                            <p class="mt-2 text-base/7 text-pretty text-white/70">{{ __('Know when to handle the next step yourself and when to bring in a qualified professional.') }}</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <footer class="bg-white py-10 dark:bg-zinc-900">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-5 sm:px-6 lg:flex-row lg:items-end lg:justify-between lg:px-8">
                <div>
                    <x-app-logo href="{{ route('home') }}" aria-label="{{ __('Mentrovia homepage') }}" />
                    <p class="mt-4 max-w-[58ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('A Texas-first business mentor for the practical work of building a durable operation.') }}</p>
                </div>
                <div class="flex flex-wrap gap-x-6 gap-y-3 text-sm text-muted dark:text-zinc-300">
                    <a href="#how-it-works" class="hover:text-ink dark:hover:text-white">{{ __('How it works') }}</a>
                    <a href="#guidance" class="hover:text-ink dark:hover:text-white">{{ __('Guidance') }}</a>
                    @guest
                        <a href="{{ route('login') }}" class="hover:text-ink dark:hover:text-white">{{ __('Log in') }}</a>
                    @endguest
                </div>
            </div>
            <div class="mx-auto mt-8 max-w-7xl border-t border-ink/10 px-5 pt-6 text-sm text-muted dark:border-white/10 dark:text-zinc-400 sm:px-6 lg:px-8">
                <x-advisory-disclaimer />
                <p class="mt-4">© {{ date('Y') }} {{ config('app.name') }}. {{ __('Open source and Texas-first.') }}</p>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
