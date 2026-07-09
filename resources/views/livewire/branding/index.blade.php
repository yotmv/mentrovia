<section class="w-full">
    <div class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Branding') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Generate a starter brand kit from your company profile: names, taglines, positioning, voice, colors, typography, image prompts, and social bios.') }}
            </flux:text>
        </div>

        @if ($this->kit !== null)
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->kits->count() > 1)
                    <flux:select wire:model.live="selectedKitId" :aria-label="__('Brand kit version')" class="max-w-40">
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
                    {{ __('Brand kits are generated from your company profile so names, taglines, and copy fit your actual industry, location, and customers.') }}
                </flux:text>
                <flux:button variant="primary" :href="route('business.intake')" wire:navigate class="mt-6">
                    {{ __('Tell us about your business') }}
                </flux:button>
            </div>
        </div>
    @else
        @if ($generationError !== null)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>{{ __('Generation failed') }}</flux:callout.heading>
                <flux:callout.text>{{ $generationError }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->kit === null)
            <div class="rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                <div class="mx-auto max-w-lg">
                    <flux:heading size="lg">{{ __('No brand kit yet') }}</flux:heading>
                    <flux:text class="mt-3">
                        {{ __('One generation produces name ideas, tagline options, a positioning statement, tone and voice notes, a hierarchical color palette, typography directions, logo image prompts, a 4K brand board prompt, and ready-to-paste social bios for :name.', ['name' => $this->business->displayName()]) }}
                    </flux:text>
                    <flux:button variant="primary" icon="sparkles" wire:click="generate" wire:loading.attr="disabled" class="mt-6">
                        <span wire:loading.remove wire:target="generate">{{ __('Generate brand kit') }}</span>
                        <span wire:loading wire:target="generate">{{ __('Generating...') }}</span>
                    </flux:button>
                    <flux:text size="sm" class="mt-4 text-zinc-500 dark:text-zinc-400">
                        {{ __('Uses AI text generation. You can regenerate any section or the whole kit later.') }}
                    </flux:text>
                </div>
            </div>
        @else
            @php
                $kit = $this->kit;
                $preferences = $kit->preferences ?? [];
                $usesProKit = App\Enums\FluxUiKit::current()->isPro();
            @endphp

            <div class="space-y-6" wire:loading.class="opacity-50" wire:target="generate">
                @include('livewire.branding.partials.picks')

                @if ($usesProKit)
                    <flux:tab.group>
                        <flux:tabs wire:model="tab">
                            <flux:tab name="identity">{{ __('Identity') }}</flux:tab>
                            <flux:tab name="design-system">{{ __('Design system') }}</flux:tab>
                            <flux:tab name="assets">{{ __('Assets') }}</flux:tab>
                        </flux:tabs>

                        <flux:tab.panel name="identity">
                            <div class="space-y-6">
                                <div class="grid gap-6 lg:grid-cols-2">
                                    @include('livewire.branding.partials.names')
                                    @include('livewire.branding.partials.taglines')
                                </div>
                                @include('livewire.branding.partials.positioning')
                            </div>
                        </flux:tab.panel>

                        <flux:tab.panel name="design-system">
                            <div class="space-y-6">
                                @include('livewire.branding.partials.palette')
                                <div class="grid gap-6 lg:grid-cols-2">
                                    @include('livewire.branding.partials.tone')
                                    @include('livewire.branding.partials.fonts')
                                </div>
                            </div>
                        </flux:tab.panel>

                        <flux:tab.panel name="assets">
                            <div class="space-y-6">
                                @include('livewire.branding.partials.board-prompt')
                                @include('livewire.branding.partials.prompts')
                                @include('livewire.branding.partials.bios')
                            </div>
                        </flux:tab.panel>
                    </flux:tab.group>
                @else
                    <div class="grid gap-6 lg:grid-cols-2">
                        @include('livewire.branding.partials.names')
                        @include('livewire.branding.partials.taglines')
                    </div>
                    @include('livewire.branding.partials.positioning')
                    @include('livewire.branding.partials.palette')
                    <div class="grid gap-6 lg:grid-cols-2">
                        @include('livewire.branding.partials.tone')
                        @include('livewire.branding.partials.fonts')
                    </div>
                    @include('livewire.branding.partials.board-prompt')
                    @include('livewire.branding.partials.prompts')
                    @include('livewire.branding.partials.bios')
                @endif

                @include('livewire.branding.partials.meta')
            </div>
        @endif
    @endif
</section>
