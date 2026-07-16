<section class="mx-auto max-w-4xl" aria-labelledby="profile-import-title">
    <div class="border-b border-ink/10 pb-7 dark:border-white/10">
        <p class="font-mono text-sm font-medium tracking-wide text-moss dark:text-sage">{{ __('Company profile') }}</p>
        <h1 id="profile-import-title" class="mt-2 font-display text-4xl tracking-tight text-ink sm:text-5xl dark:text-white">{{ __('Import a Mentrovia CSV') }}</h1>
        <p class="mt-3 max-w-3xl text-base/7 text-muted dark:text-zinc-300">{{ __('Preview one company row, choose the fields to apply, then create one encrypted profile revision. The raw file is discarded after preview.') }}</p>
    </div>

    <div class="mt-8 rounded-3xl bg-cream p-6 ring-1 ring-ink/10 dark:bg-zinc-900 dark:ring-white/10 sm:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <flux:field class="w-full max-w-xl">
                <flux:label>{{ __('CSV file') }}</flux:label>
                <flux:input type="file" wire:model="csvUpload" accept=".csv,text/csv,text/plain" />
                <flux:error name="csvUpload" />
                <flux:description>{{ __('One header row and one company row; UTF-8 CSV; 128 KB maximum.') }}</flux:description>
            </flux:field>
            <flux:button type="button" wire:click="previewCsv" variant="primary" wire:loading.attr="disabled">{{ __('Preview import') }}</flux:button>
        </div>
        <a class="mt-4 inline-block text-sm font-medium text-moss underline underline-offset-4 dark:text-sage" href="{{ route('business.intake.template') }}">{{ __('Download template and allowed values') }}</a>
    </div>

    @foreach ($warnings as $warning)
        <p role="status" class="mt-4 rounded-xl border border-gold/30 bg-gold/10 px-4 py-3 text-sm text-ink dark:text-white">{{ $warning }}</p>
    @endforeach

    @if ($preview !== [])
        <form wire:submit="apply" class="mt-8 space-y-8">
            @foreach (\App\Enums\BusinessProfileSection::cases() as $section)
                @php($rows = collect($this->previewRows)->where('section', $section->value))
                @if ($rows->isNotEmpty())
                    <section aria-labelledby="import-{{ $section->value }}">
                        <h2 id="import-{{ $section->value }}" class="font-display text-2xl text-ink dark:text-white">{{ $section->label() }}</h2>
                        <div class="mt-3 overflow-x-auto rounded-2xl ring-1 ring-ink/10 dark:ring-white/10">
                            <table class="w-full min-w-[44rem] text-left text-sm">
                                <thead class="bg-cream text-muted dark:bg-zinc-900 dark:text-zinc-400"><tr><th class="p-3">{{ __('Use') }}</th><th class="p-3">{{ __('Field') }}</th><th class="p-3">{{ __('Current') }}</th><th class="p-3">{{ __('Imported') }}</th><th class="p-3">{{ __('Result') }}</th></tr></thead>
                                <tbody class="divide-y divide-ink/10 dark:divide-white/10">
                                    @foreach ($rows as $field => $row)
                                        <tr>
                                            <td class="p-3">
                                                @if ($row['derived'])
                                                    <flux:badge color="green">{{ __('Required') }}</flux:badge>
                                                @else
                                                    <flux:checkbox wire:model.live="selections.{{ $field }}" :aria-label="__('Import :field', ['field' => $row['label']])" />
                                                @endif
                                            </td>
                                            <th scope="row" class="p-3 font-semibold text-ink dark:text-white">{{ $row['label'] }}</th>
                                            <td class="p-3 text-muted dark:text-zinc-300">{{ $this->displayValue($field, $row['current']) }}</td>
                                            <td class="p-3 text-muted dark:text-zinc-300">{{ $this->displayValue($field, $row['imported']) }}</td>
                                            <td class="p-3 font-medium text-ink dark:text-white">{{ $this->displayValue($field, $row['result']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            @endforeach

            @if ($this->derivedChanges !== [])
                <div role="status" class="rounded-2xl border border-moss/20 bg-moss/5 p-5 text-sm text-ink dark:text-white">
                    <p class="font-semibold">{{ __('Required consistency changes') }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5">@foreach ($this->derivedChanges as $change)<li>{{ $change }}</li>@endforeach</ul>
                </div>
            @endif

            @if (! $this->hasApplicableChanges)
                @if ($this->hasInvariantBlockedSelections)
                    <p role="status" class="rounded-2xl border border-gold/30 bg-gold/10 p-5 text-sm text-ink dark:text-white">{{ __('Employee consistency rules prevent the selected payroll or first employee values because employee count would remain zero. Import a positive employee count with those values, or leave them unchanged.') }}</p>
                @else
                    <p role="status" class="rounded-2xl border border-ink/10 bg-cream p-5 text-sm text-muted dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-300">{{ __('This CSV already matches the current profile.') }}</p>
                @endif
            @endif

            <div class="flex flex-wrap gap-3">
                <flux:button type="submit" variant="primary" :disabled="! $this->hasApplicableChanges">{{ __('Apply selected fields') }}</flux:button>
                <flux:button type="button" wire:click="clearPreview" variant="ghost">{{ __('Discard preview') }}</flux:button>
                <flux:button :href="route('business.edit')" wire:navigate variant="ghost">{{ __('Back to profile') }}</flux:button>
            </div>
        </form>
    @endif
</section>
