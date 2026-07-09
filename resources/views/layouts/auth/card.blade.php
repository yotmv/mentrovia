<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-cream font-sans text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <div class="flex min-h-dvh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate aria-label="{{ __('Mentrovia homepage') }}">
                    <span class="flex size-9 items-center justify-center rounded-xl bg-moss text-white dark:bg-sage dark:text-ink">
                        <x-app-logo-icon class="size-5 fill-current" />
                    </span>

                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6">
                    <div class="rounded-2xl bg-white p-10 ring-1 ring-ink/10 text-ink shadow-sm dark:bg-zinc-900 dark:ring-white/10 dark:text-zinc-100">
                        <div class="px-0 py-0">{{ $slot }}</div>
                    </div>
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
