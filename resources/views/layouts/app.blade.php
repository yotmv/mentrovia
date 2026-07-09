<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="isolate bg-paper px-4 py-6 sm:px-6 lg:px-8 dark:bg-zinc-950">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
