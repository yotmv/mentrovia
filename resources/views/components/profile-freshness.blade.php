@props(['freshness'])

<flux:badge
    size="sm"
    :color="match ($freshness) {
        App\Enums\ProfileFreshness::Current => 'green',
        App\Enums\ProfileFreshness::Stale => 'amber',
        default => 'zinc',
    }"
>
    {{ $freshness->label() }}
</flux:badge>
