<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-paper font-sans text-ink antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <a href="#main-content" class="sr-only z-50 rounded-md bg-moss px-4 py-2 text-base font-medium text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">{{ __('Skip to content') }}</a>
        <flux:sidebar sticky collapsible="mobile" class="border-e border-ink/10 bg-cream dark:border-white/10 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Your business')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Today') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="building-storefront" :href="route('business.overview')" :current="request()->routeIs('business.*')" wire:navigate>
                        {{ __('Business') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Plan and operate')" class="grid">
                    <flux:sidebar.item icon="map" :href="route('roadmap')" :current="request()->routeIs('roadmap')" wire:navigate>
                        {{ __('Plan') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="calendar-days" :href="route('tasks.index')" :current="request()->routeIs('tasks.*')" wire:navigate>
                        {{ __('Tasks') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="book-open-text" :href="route('guides.index')" :current="request()->routeIs('guides.*') || request()->routeIs('banking-setup', 'owner-pay')" wire:navigate>
                        {{ __('Guides') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Ask and learn')" class="grid">
                    <flux:sidebar.item icon="sparkles" :href="route('advisor')" :current="request()->routeIs('advisor*')" wire:navigate>
                        {{ __('Advisor') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="book-open-text" :href="route('knowledge.articles.index')" :current="request()->routeIs('knowledge.*')" wire:navigate>
                        {{ __('Knowledge') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('Grow')" class="grid">
                    <flux:sidebar.item icon="arrow-trending-up" :href="route('grow')" :current="request()->routeIs('grow', 'branding', 'advertising', 'projects.*')" wire:navigate>
                        {{ __('Growth workspace') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @if (auth()->user()?->is_admin)
                    <flux:sidebar.group :heading="__('Admin')" class="grid">
                        <flux:sidebar.item icon="shield-check" :href="route('admin.knowledge.reviews.index')" :current="request()->routeIs('admin.knowledge.reviews.*')" wire:navigate>
                            {{ __('Review Queue') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="document-text" :href="route('admin.knowledge.articles.index')" :current="request()->routeIs('admin.knowledge.articles.*')" wire:navigate>
                            {{ __('Knowledge Admin') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="border-b border-ink/10 bg-cream dark:border-white/10 dark:bg-zinc-900 lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                    <flux:text class="truncate" size="sm">{{ auth()->user()->currentAccount?->name }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                        <flux:menu.item :href="route('account.edit')" icon="building-office" wire:navigate>
                            {{ __('Workspace') }}
                        </flux:menu.item>
                        <flux:menu.item :href="route('feedback.create', ['page' => '/'.request()->path()])" icon="chat-bubble-left-right" wire:navigate>
                            {{ __('Send feedback') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
