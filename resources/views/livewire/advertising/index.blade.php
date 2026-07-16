<section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Advertising') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Generate starter advertising from your company profile and brand kit: ad angles, Facebook and Instagram copy, Google ad concepts, social posts, flyer copy, image prompts, a landing page outline, and a first 30 days plan.') }}
            </flux:text>
        </div>

        @if ($this->kit !== null)
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->kits->count() > 1)
                    <flux:select wire:model.live="selectedKitId" :aria-label="__('Advertising kit version')" class="max-w-40">
                        @foreach ($this->kits as $versionOption)
                            <flux:select.option value="{{ $versionOption->id }}">
                                {{ __('Version :version', ['version' => $versionOption->version]) }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
                <flux:button variant="primary" icon="sparkles" wire:click="generate" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="generate">{{ __('New version') }}</span>
                    <span wire:loading wire:target="generate">{{ __('Generating...') }}</span>
                </flux:button>
            </div>
        @endif
    </div>

    @if ($this->business === null)
        <div class="flex min-h-80 items-center justify-center">
            <div class="max-w-lg text-center">
                <flux:heading size="lg">{{ __('No company profile yet') }}</flux:heading>
                <flux:text class="mt-3">
                    {{ __('Advertising is generated from your company profile so angles, copy, and plans fit your actual industry, location, and customers.') }}
                </flux:text>
                <flux:button variant="primary" :href="route('business.intake')" wire:navigate class="mt-6">
                    {{ __('Tell us about your business') }}
                </flux:button>
            </div>
        </div>
    @else
        @if ($generationError !== null)
            <div role="alert" aria-live="assertive" aria-atomic="true" tabindex="-1" x-data x-init="$nextTick(() => $el.focus())" class="mb-6">
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('Generation failed') }}</flux:callout.heading>
                    <flux:callout.text>{{ $generationError }}</flux:callout.text>
                    @if ($generationErrorShowsSettings)
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('ai.edit')" wire:navigate>
                                {{ __('Review AI settings') }}
                            </flux:button>
                        </x-slot>
                    @endif
                </flux:callout>
            </div>
        @endif

        @if ($this->kit === null)
            <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                <div class="mx-auto max-w-lg">
                    <flux:heading size="lg">{{ __('No advertising kit yet') }}</flux:heading>
                    <flux:text class="mt-3">
                        {{ __('One generation produces ad angles, Facebook and Instagram ad copy, Google ad concepts, organic social posts, flyer copy, ad image prompts, a landing page outline, and a first 30 days marketing plan for :name.', ['name' => $this->business->displayName()]) }}
                    </flux:text>
                    @if ($this->brandKit === null)
                        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
                            {{ __('Ads read better when they reuse your brand voice.') }}
                            <flux:link :href="route('branding')" wire:navigate>{{ __('Generate a brand kit first') }}</flux:link>{{ __(', or continue without one.') }}
                        </flux:text>
                    @else
                        <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">
                            {{ __('Your brand kit (version :version) will keep names, tone, and colors consistent.', ['version' => $this->brandKit->version]) }}
                        </flux:text>
                    @endif
                    <flux:button variant="primary" icon="sparkles" wire:click="generate" wire:loading.attr="disabled" class="mt-6">
                        <span wire:loading.remove wire:target="generate">{{ __('Generate advertising kit') }}</span>
                        <span wire:loading wire:target="generate">{{ __('Generating...') }}</span>
                    </flux:button>
                    <flux:text size="sm" class="mt-4 text-zinc-500 dark:text-zinc-400">
                        {{ __('Uses AI text generation. You can generate a new version any time.') }}
                    </flux:text>
                </div>
            </div>
        @else
            @php
                $kit = $this->kit;
            @endphp

            <div class="space-y-6" wire:loading.class="opacity-50" wire:target="generate">
                <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div>
                        <flux:heading size="sm">{{ __('Profile freshness') }}</flux:heading>
                        <flux:text size="sm" class="mt-1 text-zinc-500 dark:text-zinc-400">
                            {{ match ($this->kitFreshness) {
                                App\Enums\ProfileFreshness::Current => __('Current: This advertising kit records the current company profile and brand kit.'),
                                App\Enums\ProfileFreshness::Stale => __('Stale: Your company profile or brand kit changed after this advertising kit was generated. Create a new version to refresh it; the saved version remains unchanged.'),
                                App\Enums\ProfileFreshness::Unknown => __('Unknown: Input version not recorded. This legacy advertising kit cannot be compared with current inputs; create a new version to regenerate it.'),
                            } }}
                        </flux:text>
                    </div>
                    <x-profile-freshness :freshness="$this->kitFreshness" />
                </div>

                <div class="grid gap-6 lg:grid-cols-2">
                    @include('livewire.advertising.partials.angles')
                    @include('livewire.advertising.partials.google-ads')
                </div>
                @include('livewire.advertising.partials.meta-ads')
                <div class="grid gap-6 lg:grid-cols-2">
                    @include('livewire.advertising.partials.posts')
                    @include('livewire.advertising.partials.flyer')
                </div>
                <div class="grid gap-6 lg:grid-cols-2">
                    @include('livewire.advertising.partials.outline')
                    @include('livewire.advertising.partials.plan')
                </div>
                @include('livewire.advertising.partials.prompts')
                @include('livewire.advertising.partials.meta')
            </div>
        @endif
    @endif
</section>
