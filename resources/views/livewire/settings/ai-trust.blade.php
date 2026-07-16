<div>
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('AI trust center') }}</flux:heading>

    <x-settings.layout :wide="true" :heading="__('AI trust center')" :subheading="__('Review account-level AI usage, routing, and the permanent audit ledger')">
        <div class="space-y-8">
            <section aria-labelledby="ai-usage-heading" class="space-y-4">
                <div>
                    <flux:heading id="ai-usage-heading" size="lg">{{ __('Current UTC month') }}</flux:heading>
                    <flux:text>{{ __('Resets :date UTC', ['date' => $usage['reset_at']->format('M j, Y H:i')]) }}</flux:text>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <flux:card>
                        <flux:text>{{ __('Successful actual cost') }}</flux:text>
                        <flux:heading size="lg">${{ number_format($usage['actual_cost'], 6) }}</flux:heading>
                    </flux:card>
                    <flux:card>
                        <flux:text>{{ __('Outstanding reservations') }}</flux:text>
                        <flux:heading size="lg">${{ number_format($usage['reserved_cost'], 6) }}</flux:heading>
                    </flux:card>
                    <flux:card>
                        <flux:text>{{ __('Limit / remaining') }}</flux:text>
                        <flux:heading size="lg">
                            @if ($usage['limit'] === null)
                                {{ __('Unlimited') }}
                            @else
                                ${{ number_format($usage['limit'], 2) }} / ${{ number_format($usage['remaining'], 2) }}
                            @endif
                        </flux:heading>
                    </flux:card>
                    <flux:card>
                        <flux:text>{{ __('Concurrency') }}</flux:text>
                        <flux:heading size="lg">{{ $usage['concurrency_used'] }} / {{ $usage['concurrency_limit'] }}</flux:heading>
                    </flux:card>
                </div>
            </section>

            <flux:separator />

            <section aria-labelledby="ai-routing-heading" class="space-y-4">
                <div>
                    <flux:heading id="ai-routing-heading" size="lg">{{ __('Effective routing') }}</flux:heading>
                    <flux:text>{{ __('Resolved in account policy order. Custom models are attempted in the order shown.') }}</flux:text>
                </div>

                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($routing as $route)
                        <flux:card wire:key="route-{{ $route['purpose'] }}" class="space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <flux:heading size="sm">{{ str($route['purpose'])->replace('_', ' ')->title() }}</flux:heading>
                                <div class="flex gap-2">
                                    <flux:badge>{{ str($route['mode'])->title() }}</flux:badge>
                                    <flux:badge :color="$route['route'] === 'disabled' ? 'red' : ($route['route'] === 'byok' ? 'blue' : 'green')">
                                        {{ str($route['route'])->upper() }}
                                    </flux:badge>
                                </div>
                            </div>
                            @if ($route['models'] === [])
                                <flux:text>{{ __('No model can be routed while this purpose is disabled.') }}</flux:text>
                            @else
                                <ol class="space-y-1 text-sm text-zinc-700 dark:text-zinc-300">
                                    @foreach ($route['models'] as $index => $resolvedModel)
                                        <li class="break-all"><span class="font-mono text-xs text-zinc-500">{{ $index + 1 }}.</span> {{ $resolvedModel }}</li>
                                    @endforeach
                                </ol>
                            @endif
                        </flux:card>
                    @endforeach
                </div>
            </section>

            <flux:separator />

            <section aria-labelledby="ai-preflight-heading" class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <flux:heading id="ai-preflight-heading" size="lg">{{ __('OpenRouter preflight') }}</flux:heading>
                        <flux:text>{{ __('Validate the active encrypted key and configured model modalities without generating content or spending on inference.') }}</flux:text>
                    </div>
                    <flux:button type="button" wire:click="runPreflight" wire:loading.attr="disabled" wire:target="runPreflight" icon="shield-check">
                        <span wire:loading.remove wire:target="runPreflight">{{ __('Run preflight') }}</span>
                        <span wire:loading wire:target="runPreflight">{{ __('Checking…') }}</span>
                    </flux:button>
                </div>

                <flux:error name="preflight" />

                @if ($preflightResult !== [])
                    <flux:callout :variant="$preflightResult['status'] === 'succeeded' ? 'success' : ($preflightResult['status'] === 'failed' ? 'danger' : 'warning')" icon="shield-check">
                        <div>{{ $preflightResult['message'] }}</div>
                        <div class="mt-1 text-xs">
                            {{ __('Key: :status', ['status' => $preflightResult['key_valid'] === true ? __('valid') : ($preflightResult['key_valid'] === false ? __('invalid') : __('not checked'))]) }}
                            @if ($preflightResult['label'])
                                · {{ __('Label: :label', ['label' => $preflightResult['label']]) }}
                            @endif
                            · {{ __('Operation: :operation', ['operation' => $preflightResult['operation_id']]) }}
                        </div>
                    </flux:callout>

                    @if ($preflightResult['models'] !== [])
                        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-700">
                                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-300">
                                    <tr>
                                        <th scope="col" class="px-4 py-3">{{ __('Purpose') }}</th>
                                        <th scope="col" class="px-4 py-3">{{ __('Model') }}</th>
                                        <th scope="col" class="px-4 py-3">{{ __('Required') }}</th>
                                        <th scope="col" class="px-4 py-3">{{ __('Result') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                    @foreach ($preflightResult['models'] as $index => $modelResult)
                                        <tr wire:key="preflight-model-{{ $index }}">
                                            <td class="px-4 py-3">{{ str($modelResult['purpose'])->replace('_', ' ')->title() }}</td>
                                            <td class="max-w-96 break-all px-4 py-3">{{ $modelResult['model'] }}</td>
                                            <td class="px-4 py-3">{{ str($modelResult['required_modality'])->title() }}</td>
                                            <td class="px-4 py-3">
                                                <flux:badge :color="$modelResult['exists'] && $modelResult['compatible'] ? 'green' : 'red'">
                                                    {{ ! $modelResult['exists'] ? __('Missing') : ($modelResult['compatible'] ? __('Compatible') : __('Incompatible')) }}
                                                </flux:badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endif
            </section>

            <flux:separator />

            <section aria-labelledby="ai-audit-heading" class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <flux:heading id="ai-audit-heading" size="lg">{{ __('Permanent audit timeline') }}</flux:heading>
                        <flux:text>{{ __('Prompts, outputs, keys, response bodies, and raw HTTP data are never shown or exported.') }}</flux:text>
                    </div>
                    <flux:button :href="$exportUrl" icon="arrow-down-tray">{{ __('Export filtered CSV') }}</flux:button>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <flux:select wire:model.live="event" :label="__('Event')">
                        <flux:select.option value="">{{ __('All events') }}</flux:select.option>
                        @foreach ($eventOptions as $eventOption)
                            <flux:select.option :value="$eventOption->value">{{ str($eventOption->value)->replace('_', ' ')->title() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="outcome" :label="__('Outcome')">
                        <flux:select.option value="">{{ __('All outcomes') }}</flux:select.option>
                        @foreach (['started', 'succeeded', 'failed', 'prevented', 'recorded'] as $outcomeOption)
                            <flux:select.option :value="$outcomeOption">{{ str($outcomeOption)->title() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="actor" :label="__('Actor')">
                        <flux:select.option value="">{{ __('All actors') }}</flux:select.option>
                        @foreach ($actors as $actorOption)
                            <flux:select.option :value="$actorOption['id']">
                                {{ $actorOption['name'] ?? __('Deleted user #:id', ['id' => $actorOption['id']]) }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="purpose" :label="__('Purpose')">
                        <flux:select.option value="">{{ __('All purposes') }}</flux:select.option>
                        @foreach ($purposeOptions as $purposeOption)
                            <flux:select.option :value="$purposeOption->value">{{ str($purposeOption->value)->replace('_', ' ')->title() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model.live.debounce.350ms="provider" :label="__('Provider')" maxlength="40" />
                    <flux:input wire:model.live.debounce.350ms="model" :label="__('Model contains')" maxlength="80" />
                    <flux:input wire:model.live.debounce.350ms="operationId" :label="__('Operation ID')" />
                    <flux:input wire:model.live="dateFrom" type="date" :label="__('From date (UTC)')" />
                    <flux:input wire:model.live="dateTo" type="date" :label="__('Through date (UTC)')" />
                </div>

                <div class="flex justify-end">
                    <flux:button type="button" wire:click="resetFilters" variant="ghost">{{ __('Reset filters') }}</flux:button>
                </div>

                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-zinc-700">
                            <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-300">
                                <tr>
                                    <th scope="col" class="px-4 py-3">{{ __('When / actor') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Event') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Route') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Cost') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Fingerprint / operation') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 bg-white align-top dark:divide-zinc-700 dark:bg-zinc-900">
                                @forelse ($audits as $audit)
                                    @php($fingerprint = $audit->credential_fingerprint ?? $audit->request_hash ?? $audit->after_fingerprint ?? $audit->before_fingerprint)
                                    <tr wire:key="audit-{{ $audit->id }}">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <div>{{ $audit->occurred_at->utc()->format('Y-m-d H:i:s') }} UTC</div>
                                            <div class="text-xs text-zinc-500">
                                                {{ $audit->getAttribute('actor_name') ?? ($audit->actor_user_id ? __('Deleted user #:id', ['id' => $audit->actor_user_id]) : __('System')) }}
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>{{ str($audit->event->value)->replace('_', ' ')->title() }}</div>
                                            <div class="text-xs text-zinc-500">{{ str($audit->event->outcome())->title() }}</div>
                                            @if (($audit->changed_fields ?? []) !== [])
                                                <div class="mt-1 max-w-64 text-xs text-zinc-500">{{ implode(', ', $audit->changed_fields) }}</div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>{{ $audit->purpose?->value ? str($audit->purpose->value)->replace('_', ' ')->title() : '—' }}</div>
                                            <div class="max-w-72 break-all text-xs text-zinc-500">{{ $audit->provider ?? '—' }} / {{ $audit->model ?? '—' }}</div>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            @if ($audit->event === \App\Enums\AiAuditEvent::Succeeded && $audit->cost_usd !== null)
                                                <div>${{ number_format((float) $audit->cost_usd, 6) }} {{ __('actual') }}</div>
                                            @elseif ($audit->event === \App\Enums\AiAuditEvent::Started && $audit->cost_usd !== null)
                                                <div>${{ number_format((float) $audit->cost_usd, 6) }} {{ __('reserved') }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs">
                                            <div>{{ $fingerprint ? str($fingerprint)->limit(16, '') : '—' }}</div>
                                            <div class="mt-1 break-all text-zinc-500">{{ $audit->operation_id }}</div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-10 text-center text-zinc-500">{{ __('No audit events match these filters.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{ $audits->links() }}
            </section>
        </div>
    </x-settings.layout>
</div>
