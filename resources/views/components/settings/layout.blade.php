@props(['wide' => false, 'heading' => '', 'subheading' => ''])

<div class="flex items-start max-md:flex-col">
    @php($settingsAccount = auth()->user()?->currentAccount)

    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('account.edit')" wire:navigate>{{ __('Workspace') }}</flux:navlist.item>
            @if ($settingsAccount !== null && auth()->user()->can('manageBilling', $settingsAccount))
                <flux:navlist.item :href="route('billing.edit')" wire:navigate>{{ __('Billing') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            @if ($settingsAccount !== null && auth()->user()->can('manageAi', $settingsAccount))
                <flux:navlist.item :href="route('ai.edit')" wire:navigate>{{ __('AI controls') }}</flux:navlist.item>
                <flux:navlist.item :href="route('ai.trust')" wire:navigate>{{ __('AI trust center') }}</flux:navlist.item>
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading }}</flux:heading>
        <flux:subheading>{{ $subheading }}</flux:subheading>

        <div @class(['mt-5 w-full', 'max-w-4xl' => $wide, 'max-w-lg' => ! $wide])>
            {{ $slot }}
        </div>
    </div>
</div>
