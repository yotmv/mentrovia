<flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
    {{ __('Version :version, generated :date.', [
        'version' => $kit->version,
        'date' => $kit->generated_at?->format('M j, Y g:i A') ?? __('unknown'),
    ]) }}
</flux:text>
