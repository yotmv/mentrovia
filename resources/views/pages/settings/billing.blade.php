<x-layouts::app :title="__('Billing settings')">
    <section class="w-full">
        @include('partials.settings-heading')

        <flux:heading class="sr-only">{{ __('Billing settings') }}</flux:heading>

        <x-settings.layout wide :heading="__('Billing')" :subheading="__('Manage subscription billing for :workspace.', ['workspace' => $billing['workspace_name']])">
            <div class="space-y-8">
                @if (request()->query('billing') === 'pending')
                    <flux:callout variant="success" icon="clock">
                        <flux:callout.heading>{{ __('Billing confirmation is pending') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Stripe is processing your checkout. Access changes only after the signed billing update reaches this workspace.') }}</flux:callout.text>
                    </flux:callout>
                @elseif (request()->query('billing') === 'canceled')
                    <flux:callout variant="warning" icon="information-circle">
                        <flux:callout.heading>{{ __('Checkout was canceled') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('No subscription change was confirmed. Your current workspace access remains as shown below.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($errors->hasAny(['interval', 'billing']))
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:error name="interval" />
                        <flux:error name="billing" />
                    </flux:callout>
                @endif

                <section aria-labelledby="billing-access-heading" class="space-y-4">
                    <div>
                        <flux:heading id="billing-access-heading" size="lg">{{ __('Workspace access') }}</flux:heading>
                        <flux:text>{{ __('This provider-neutral entitlement is the source of truth for feature access.') }}</flux:text>
                    </div>

                    <flux:card class="space-y-5">
                        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div class="space-y-1">
                                <flux:text size="sm">{{ __('Current plan') }}</flux:text>
                                <flux:heading size="xl">{{ $billing['plan_label'] }}</flux:heading>
                                <flux:text>{{ $billing['entitlement_description'] }}</flux:text>
                            </div>

                            <flux:badge :color="$billing['entitlement_color']">{{ $billing['entitlement_label'] }}</flux:badge>
                        </div>

                        @if ($billing['entitlement_trial_ends'])
                            <div class="rounded-lg bg-zinc-50 p-4 dark:bg-zinc-800/60">
                                <flux:text size="sm">{{ __('Trial ends') }}</flux:text>
                                <flux:heading>{{ $billing['entitlement_trial_ends'] }}</flux:heading>
                            </div>
                        @endif
                    </flux:card>
                </section>

                @if ($billing['subscription'])
                    <section aria-labelledby="billing-subscription-heading" class="space-y-4">
                        <div>
                            <flux:heading id="billing-subscription-heading" size="lg">{{ __('Stripe subscription') }}</flux:heading>
                            <flux:text>{{ __('This status comes from the latest signed Stripe update stored locally.') }}</flux:text>
                        </div>

                        <flux:card class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                            <div class="space-y-1">
                                <flux:heading>{{ __('Standard subscription') }}</flux:heading>
                                @if ($billing['subscription']['detail'])
                                    <flux:text>{{ $billing['subscription']['detail'] }}</flux:text>
                                @endif
                            </div>
                            <flux:badge :color="$billing['subscription']['color']">{{ $billing['subscription']['label'] }}</flux:badge>
                        </flux:card>
                    </section>
                @endif

                @if ($billing['billing_profile_inconsistent'])
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Billing needs support') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('The active access record and local Stripe billing evidence do not agree. Checkout is disabled to prevent duplicate billing; contact support.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($billing['checkout_pending'])
                    <flux:callout variant="warning" icon="clock">
                        <flux:callout.heading>{{ __('Checkout confirmation is still pending') }}</flux:callout.heading>
                        <flux:callout.text>{{ $billing['checkout_pending_description'] }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if (! $billing['billing_profile_inconsistent'] && $billing['show_portal'])
                    <section aria-labelledby="billing-portal-heading" class="space-y-4">
                        <div>
                            <flux:heading id="billing-portal-heading" size="lg">{{ __('Manage billing in Stripe') }}</flux:heading>
                            <flux:text>{{ __('Update the payment method, manage the subscription, and review invoices in Stripe’s secure billing portal.') }}</flux:text>
                        </div>

                        <form method="POST" action="{{ route('billing.portal') }}">
                            @csrf
                            <flux:button type="submit" variant="primary" icon="arrow-top-right-on-square">
                                {{ __('Open Stripe billing portal') }}
                            </flux:button>
                        </form>
                    </section>
                @endif

                @if (! $billing['billing_profile_inconsistent'] && $billing['checkout_intervals'] !== [])
                    <section aria-labelledby="billing-checkout-heading" class="space-y-4">
                        <div>
                            <flux:heading id="billing-checkout-heading" size="lg">{{ __('Choose a billing interval') }}</flux:heading>
                            <flux:text>{{ __('Price, tax, and renewal details are shown in Stripe Checkout before payment. Checkout does not grant access until its signed update is processed.') }}</flux:text>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($billing['checkout_intervals'] as $interval)
                                <flux:card wire:key="billing-interval-{{ $interval['value'] }}" class="flex flex-col justify-between gap-5">
                                    <div class="space-y-2">
                                        <flux:heading>{{ $interval['label'] }}</flux:heading>
                                        <flux:text>{{ $interval['description'] }}</flux:text>
                                    </div>

                                    <form method="POST" action="{{ route('billing.checkout') }}">
                                        @csrf
                                        <input type="hidden" name="interval" value="{{ $interval['value'] }}">
                                        <flux:button type="submit" variant="primary" class="w-full">
                                            {{ __('Continue with :interval billing', ['interval' => str($interval['label'])->lower()]) }}
                                        </flux:button>
                                    </form>
                                </flux:card>
                            @endforeach
                        </div>
                    </section>
                @elseif (! $billing['billing_profile_inconsistent'] && $billing['checkout_unavailable'])
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Subscription checkout is unavailable') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('No billing intervals are configured. Contact support before the current access period ends.') }}</flux:callout.text>
                    </flux:callout>
                @endif

                <flux:text size="sm">{{ __('Opening Checkout or the billing portal requires a recently confirmed password.') }}</flux:text>
            </div>
        </x-settings.layout>
    </section>
</x-layouts::app>
