<section class="mx-auto max-w-5xl" aria-labelledby="profile-editor-title">
    <div class="flex flex-col gap-5 border-b border-ink/10 pb-7 dark:border-white/10 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Company profile') }}</p>
            <h1 id="profile-editor-title" class="mt-2 font-display text-4xl tracking-tight text-ink sm:text-5xl dark:text-white">
                {{ $this->currentSection?->label() ?? __('Keep your operating facts current') }}
            </h1>
            <p class="mt-3 max-w-3xl text-base/7 text-muted dark:text-zinc-300">
                {{ $this->section ? __('Only fields you change are saved. Untouched fields can safely merge with teammates’ edits.') : __('These facts keep your roadmap, recurring work, guides, and AI tools grounded in the company you run today.') }}
            </p>
            @if ($this->section === null)
                <p class="mt-2 text-sm text-muted dark:text-zinc-400">
                    {{ __('Current stage: :stage', ['stage' => $this->business?->stage?->label() ?? __('Not classified')]) }}
                    <span aria-hidden="true"> · </span>
                    {{ $this->latestVersion ? __('Last profile update :time', ['time' => $this->latestVersion->created_at?->diffForHumans()]) : __('No versioned update recorded yet') }}
                </p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('business.profile.history')" wire:navigate variant="ghost" icon="clock">{{ __('History') }}</flux:button>
            @if ($this->isManager)
                <flux:button :href="route('business.profile.import')" wire:navigate variant="ghost" icon="arrow-up-tray">{{ __('Import CSV') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($this->section === null)
        @if ($this->latestVersion)
            <div class="mt-6 rounded-2xl border border-moss/20 bg-moss/5 px-5 py-4 text-sm text-ink dark:text-zinc-200">
                <span class="font-semibold">{{ __('Most recent change:') }}</span>
                {{ $this->latestVersion->changed_field_keys === [] ? __('Legacy profile baseline recorded') : collect($this->latestVersion->changed_field_keys)->map(fn ($field) => str($field)->replace('_', ' ')->title())->join(', ') }}
            </div>
        @endif
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            @foreach ($this->sections as $section)
                <a href="{{ route('business.profile.section', $section->value) }}" wire:navigate class="group rounded-3xl bg-cream p-6 ring-1 ring-ink/10 transition hover:-translate-y-0.5 hover:ring-moss/50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-moss dark:bg-zinc-900 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <h2 class="font-display text-2xl text-ink dark:text-white">{{ $section->label() }}</h2>
                        <flux:icon.arrow-right class="size-5 text-moss transition group-hover:translate-x-1 dark:text-sage" />
                    </div>
                    <dl class="mt-5 space-y-2">
                        @foreach (array_slice($section->fields(), 0, 3) as $field)
                            <div class="grid grid-cols-[8rem_1fr] gap-3 text-sm">
                                <dt class="text-muted dark:text-zinc-400">{{ str($field)->replace('_', ' ')->title() }}</dt>
                                <dd class="truncate font-medium text-ink dark:text-white">{{ $this->displayValue($field, data_get($this->business, $field)) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </a>
            @endforeach
        </div>
    @else
        <form wire:submit="save" class="mt-8 max-w-2xl">
            @if ($errors->has('profile'))
                <div role="alert" tabindex="-1" x-init="$el.focus()" class="mb-6 rounded-2xl border border-rust/30 bg-rust/10 p-5 text-sm text-ink focus:outline-none dark:text-white">
                    <p class="font-semibold">{{ $errors->first('profile') }}</p>
                    @foreach ($conflicts as $field => $conflict)
                        <div class="mt-4 grid gap-2 sm:grid-cols-2">
                            <p><span class="block text-xs font-semibold uppercase tracking-wide text-muted dark:text-zinc-400">{{ __('Current :field', ['field' => str($field)->replace('_', ' ')->title()]) }}</span>{{ $this->displayValue($field, $conflict['current']) }}</p>
                            <p><span class="block text-xs font-semibold uppercase tracking-wide text-muted dark:text-zinc-400">{{ __('Your entry') }}</span>{{ $this->displayValue($field, $conflict['yours']) }}</p>
                        </div>
                    @endforeach
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button type="button" wire:click="keepMyEdits" variant="primary" size="sm">{{ __('Keep my edits') }}</flux:button>
                        <flux:button type="button" wire:click="reloadCurrent" variant="ghost" size="sm">{{ __('Reload current values') }}</flux:button>
                    </div>
                </div>
            @endif

            <div class="grid gap-6 rounded-3xl bg-cream p-6 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:grid-cols-2 sm:p-8">
                @foreach ($this->fields as $field => $definition)
                    <flux:field class="{{ in_array($field, ['address', 'industry'], true) ? 'sm:col-span-2' : '' }}">
                        @if ($definition['type'] === 'checkbox')
                            <flux:checkbox wire:model="values.{{ $field }}" :label="$definition['label']" />
                        @elseif ($definition['type'] === 'select')
                            <flux:label>{{ $definition['label'] }}</flux:label>
                            <flux:select wire:model="values.{{ $field }}">
                                @foreach ($definition['options'] as $optionValue => $optionLabel)
                                    <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:label>{{ $definition['label'] }}</flux:label>
                            <flux:input wire:model="values.{{ $field }}" :type="$definition['type']" :min="$definition['type'] === 'number' ? 0 : null" />
                        @endif
                        <flux:error name="{{ $field }}" />
                    </flux:field>
                @endforeach
            </div>

            @if ($this->section === 'people_obligations')
                <p class="mt-4 text-sm/6 text-muted dark:text-zinc-400">{{ __('Setting employees to zero also clears the first employee date and payroll system in the same saved revision.') }}</p>
            @endif
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ __('Save section') }}</flux:button>
                <flux:button :href="route('business.edit')" wire:navigate variant="ghost">{{ __('Back to profile') }}</flux:button>
                <p aria-live="polite" class="text-sm text-muted dark:text-zinc-400">{{ $savedStatus }}</p>
            </div>
        </form>
    @endif
</section>
