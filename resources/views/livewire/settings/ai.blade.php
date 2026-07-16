<div>
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('AI controls') }}</flux:heading>

    <x-settings.layout :heading="__('AI controls')" :subheading="__('Control paid AI access, budgets, providers, and model routing for your account')">
        <div class="space-y-8">
            <form wire:submit="saveSettings" class="space-y-5">
                <div class="space-y-3">
                    <flux:switch wire:model="paidAiEnabled" :label="__('Enable paid AI operations')" />
                    <flux:switch wire:model="hostedAiEnabled" :label="__('Allow Mentrovia-hosted AI')" />
                    <flux:switch wire:model="byokEnabled" :label="__('Use my OpenRouter key when available')" />
                    <flux:error name="byokEnabled" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="monthlyUsdLimit" type="number" min="0.01" step="0.01" :label="__('Monthly limit (USD)')" placeholder="No limit" />
                    <flux:input wire:model="perOperationUsdLimit" type="number" min="0.0001" step="0.0001" :label="__('Per-operation limit (USD)')" placeholder="No limit" />
                </div>

                <flux:input wire:model="maxConcurrency" type="number" min="1" max="10" :label="__('Maximum concurrent operations')" />

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Save controls') }}</flux:button>
                </div>
            </form>

            <flux:separator />

            <section class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('OpenRouter BYOK') }}</flux:heading>
                    <flux:text>{{ __('The key is encrypted and write-only. Mentrovia permanently audits every in-app use by fingerprint, model, status, hashes, and usage metadata—never the key, prompt, or output.') }}</flux:text>
                    <flux:text class="mt-2">{{ __('Use outside Mentrovia cannot be observed here. Review OpenRouter activity and rotate the key immediately if you suspect theft.') }}</flux:text>
                </div>

                @if ($credentialLastFour)
                    <flux:callout icon="key">
                        {{ __('Active OpenRouter key ending in :lastFour', ['lastFour' => $credentialLastFour]) }}
                    </flux:callout>
                @endif

                @if (session('status'))
                    <flux:callout variant="success">{{ session('status') }}</flux:callout>
                @endif

                <form method="POST" action="{{ route('ai.credential.store') }}" class="space-y-3">
                    @csrf
                    <flux:input name="openrouter_api_key" type="password" autocomplete="new-password" :label="$credentialLastFour ? __('Rotate OpenRouter API key') : __('OpenRouter API key')" />
                    <flux:error name="openrouter_api_key" />
                    <div class="flex flex-wrap items-center gap-3">
                        <flux:button type="submit" variant="primary">{{ $credentialLastFour ? __('Rotate key') : __('Save key') }}</flux:button>
                    </div>
                </form>

                @if ($credentialLastFour)
                    <form method="POST" action="{{ route('ai.credential.destroy') }}">
                        @csrf
                        @method('DELETE')
                        <flux:button type="submit" variant="danger">{{ __('Revoke key') }}</flux:button>
                    </form>
                @endif
            </section>

            <flux:separator />

            <form wire:submit="saveModels" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Model routing') }}</flux:heading>
                    <flux:text>{{ __('Choose the curated Auto list or enter ordered OpenRouter model IDs. The first custom model is used first.') }}</flux:text>
                </div>

                @foreach (\App\Enums\AiModelPurpose::cases() as $purpose)
                    @php($key = $purpose->value)
                    <fieldset wire:key="model-purpose-{{ $key }}" class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                        <flux:heading size="sm">{{ str($key)->replace('_', ' ')->title() }}</flux:heading>
                        @if ($purpose === \App\Enums\AiModelPurpose::Image)
                            <flux:text>{{ __('Add up to :count models. Each model generates one result in the order shown.', ['count' => config('account-ai.max_custom_models_by_purpose.image', 3)]) }}</flux:text>
                        @endif
                        @if ($purpose !== \App\Enums\AiModelPurpose::Auto)
                            <flux:radio.group wire:model.live="modelModes.{{ $key }}" :label="__('Routing mode')" variant="segmented">
                                <flux:radio value="auto" :label="__('Auto')" />
                                <flux:radio value="custom" :label="__('Custom')" />
                            </flux:radio.group>
                        @endif

                        @if (($modelModes[$key] ?? 'auto') === 'auto')
                            <div class="flex flex-wrap gap-2">
                                @foreach (config("account-ai.auto_models.{$key}", []) as $model)
                                    <flux:badge wire:key="auto-{{ $key }}-{{ $model }}">{{ $model }}</flux:badge>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-wrap gap-2">
                                @foreach ($models[$key] ?? [] as $index => $model)
                                    <flux:badge wire:key="custom-{{ $key }}-{{ $index }}" color="blue">
                                        {{ $model }}
                                        <button type="button" wire:click="removeModel('{{ $key }}', {{ $index }})" class="ms-1" aria-label="{{ __('Remove :model', ['model' => $model]) }}">&times;</button>
                                    </flux:badge>
                                @endforeach
                            </div>
                            <div class="flex items-end gap-2">
                                <flux:input wire:model="newModels.{{ $key }}" :label="__('Add model ID')" placeholder="provider/model-name" />
                                <flux:button type="button" wire:click="addModel('{{ $key }}')">{{ __('Add') }}</flux:button>
                            </div>
                            <flux:error name="models.{{ $key }}" />
                        @endif
                    </fieldset>
                @endforeach

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary">{{ __('Save model routing') }}</flux:button>
                </div>
            </form>
        </div>
    </x-settings.layout>
</div>
