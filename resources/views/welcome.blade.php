<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @php($title = __('Your Texas Small Business Mentor'))
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 px-6 py-6">
            <x-app-logo />

            <nav class="flex items-center gap-2">
                @auth
                    <flux:button variant="primary" :href="route('dashboard')">{{ __('Go to dashboard') }}</flux:button>
                @else
                    <flux:button variant="ghost" :href="route('login')">{{ __('Log in') }}</flux:button>
                    @if (Route::has('register'))
                        <flux:button variant="primary" :href="route('register')">{{ __('Get started') }}</flux:button>
                    @endif
                @endauth
            </nav>
        </header>

        <main class="mx-auto w-full max-w-5xl px-6">
            <section class="py-16 text-center sm:py-24">
                <flux:badge color="amber" size="sm" class="mb-6">{{ __('Texas-first · Beta') }}</flux:badge>
                <h1 class="mx-auto max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                    {{ __('Know exactly what your Texas business needs next.') }}
                </h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">
                    {{ __('Answer a few questions about your business and Mentrovia builds your personalized setup roadmap, flags compliance risks, and keeps you on top of the recurring tasks that keep small businesses out of trouble.') }}
                </p>
                <div class="mt-8 flex items-center justify-center gap-3">
                    @auth
                        <flux:button variant="primary" :href="route('dashboard')">{{ __('Open your dashboard') }}</flux:button>
                    @else
                        @if (Route::has('register'))
                            <flux:button variant="primary" :href="route('register')">{{ __('Start free') }}</flux:button>
                        @endif
                        <flux:button variant="ghost" :href="route('login')">{{ __('Log in') }}</flux:button>
                    @endauth
                </div>
            </section>

            <section class="grid gap-6 pb-16 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:icon.clipboard-document-list class="size-6 text-zinc-500 dark:text-zinc-400" />
                    <h2 class="mt-4 font-medium">{{ __('Guided intake') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('A five-step questionnaire captures your entity status, taxes, banking, and staffing — then classifies your business stage.') }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:icon.map class="size-6 text-zinc-500 dark:text-zinc-400" />
                    <h2 class="mt-4 font-medium">{{ __('Personalized roadmap') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Formation, taxes, banking, payroll, and owner pay steps — prioritized, with plain-English reasons why each one matters.') }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:icon.exclamation-triangle class="size-6 text-zinc-500 dark:text-zinc-400" />
                    <h2 class="mt-4 font-medium">{{ __('Risk flags') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Commingled bank accounts, missing permits, employees without payroll — surfaced early, before they become expensive.') }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                    <flux:icon.book-open class="size-6 text-zinc-500 dark:text-zinc-400" />
                    <h2 class="mt-4 font-medium">{{ __('Source-backed guidance') }}</h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Texas-specific knowledge articles cite official state and federal sources, with last-verified dates on every one.') }}
                    </p>
                </div>
            </section>
        </main>

        <footer class="mx-auto w-full max-w-5xl px-6 pb-10">
            <flux:separator class="mb-6" />
            <x-advisory-disclaimer />
            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                © {{ date('Y') }} {{ config('app.name') }} · {{ __('Open source, Texas-first.') }}
            </p>
        </footer>

        @fluxScripts
    </body>
</html>
