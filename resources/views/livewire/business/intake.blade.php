<section class="mx-auto w-full max-w-2xl">
    <div class="mb-8">
        <flux:heading size="xl">{{ __('Company profile') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Tell us about your business so Mentrovia can build your personalized roadmap and task list.') }}
        </flux:text>
    </div>

    @if ($errors->any())
        <div
            class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-red-950 outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-2 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100"
            role="alert"
            tabindex="-1"
            x-data
            x-init="$nextTick(() => $el.focus())"
        >
            <p class="font-semibold">{{ __('Please review these profile issues:') }}</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($errors->all() as $message)
                    <li wire:key="intake-error-{{ $loop->index }}">{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($this->isEstablishedTrack && $existingBusinessId === null)
        <div class="mb-8 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Import an existing company profile') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Upload one company row for review. Nothing changes until you choose the fields and apply them.') }}
            </flux:text>

            <form wire:submit="previewCsv" class="mt-4 space-y-3">
                <flux:input
                    wire:model="csvUpload"
                    type="file"
                    accept=".csv,text/csv"
                    :label="__('Company CSV')"
                    :description="__('CSV only, up to 128 KB. The uploaded file is discarded after parsing.')"
                />

                <div class="flex flex-wrap items-center gap-3">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="csvUpload,previewCsv">
                        {{ __('Preview import') }}
                    </flux:button>
                    <flux:button :href="route('business.intake.template')" variant="ghost" icon="arrow-down-tray">
                        {{ __('Download template') }}
                    </flux:button>
                    <flux:text wire:loading wire:target="csvUpload,previewCsv" size="sm" role="status" aria-live="polite">
                        {{ __('Reading CSV…') }}
                    </flux:text>
                </div>
            </form>

            <details class="mt-4 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800/60">
                <summary class="cursor-pointer font-medium text-zinc-800 dark:text-zinc-100">{{ __('CSV field guide') }}</summary>
                <dl class="mt-3 grid gap-2 sm:grid-cols-[minmax(10rem,1fr)_2fr]">
                    @foreach ($this->csvFieldGuide as $field => $guidance)
                        <dt class="font-mono text-xs text-zinc-700 dark:text-zinc-300" wire:key="csv-guide-field-{{ $loop->index }}">{{ $field }}</dt>
                        <dd class="text-zinc-600 dark:text-zinc-400" wire:key="csv-guide-description-{{ $loop->index }}">{{ $guidance }}</dd>
                    @endforeach
                </dl>
            </details>

            @if ($importWarnings !== [])
                <flux:callout class="mt-4" icon="exclamation-triangle" variant="warning">
                    <flux:callout.heading>{{ __('Import warnings') }}</flux:callout.heading>
                    <flux:callout.text>
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($importWarnings as $warning)
                                <li wire:key="csv-warning-{{ $loop->index }}">{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($importPreview !== [])
                <form wire:submit="applyCsv" class="mt-5 space-y-3">
                    <flux:heading size="sm">{{ __('Choose fields to apply') }}</flux:heading>
                    <div class="space-y-2">
                        @foreach ($importPreview as $property => $preview)
                            @php($result = ($importSelections[$property] ?? false) ? $preview['imported'] : $preview['current'])
                            <label class="block rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="csv-preview-{{ $property }}">
                                <div class="flex items-start gap-3">
                                    <input wire:model="importSelections.{{ $property }}" type="checkbox" class="mt-1 rounded border-zinc-300 text-moss focus:ring-moss dark:border-zinc-600" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-medium text-zinc-900 dark:text-zinc-100">{{ $preview['label'] }}</span>
                                        <span class="mt-2 grid gap-2 text-sm sm:grid-cols-3">
                                            <span class="rounded-md bg-zinc-50 p-2 dark:bg-zinc-800">
                                                <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Current') }}</span>
                                                <span class="break-words text-zinc-800 dark:text-zinc-200">
                                                    {{ is_bool($preview['current']) ? ($preview['current'] ? __('Yes') : __('No')) : ($preview['current'] === null || $preview['current'] === '' ? '—' : $preview['current']) }}
                                                </span>
                                            </span>
                                            <span class="rounded-md bg-sage/30 p-2 dark:bg-sage/10">
                                                <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Imported') }}</span>
                                                <span class="break-words text-zinc-800 dark:text-zinc-200">
                                                    {{ is_bool($preview['imported']) ? ($preview['imported'] ? __('Yes') : __('No')) : $preview['imported'] }}
                                                </span>
                                            </span>
                                            <span class="rounded-md bg-moss/10 p-2 ring-1 ring-moss/20 dark:bg-moss/20 dark:ring-sage/30">
                                                <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-300">{{ __('Result') }}</span>
                                                <span class="break-words font-medium text-zinc-900 dark:text-zinc-100">
                                                    {{ is_bool($result) ? ($result ? __('Yes') : __('No')) : ($result === null || $result === '' ? '—' : $result) }}
                                                </span>
                                            </span>
                                        </span>
                                    </span>
                                </div>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="applyCsv">
                            {{ __('Apply selected fields') }}
                        </flux:button>
                        <flux:button type="button" variant="ghost" wire:click="clearImportPreview">
                            {{ __('Cancel preview') }}
                        </flux:button>
                        <flux:text wire:loading wire:target="applyCsv" size="sm" role="status" aria-live="polite">
                            {{ __('Applying import…') }}
                        </flux:text>
                    </div>
                </form>
            @endif
        </div>
    @endif

    <div class="mb-6">
        <div class="mb-2 flex items-center justify-between gap-4">
            <flux:text size="sm" variant="strong">
                {{ __('Step :current of :total', ['current' => $step, 'total' => $this->lastStep]) }}:
                {{ $this->stepLabels[$step] }}
            </flux:text>
            <flux:text size="sm">{{ (int) round($step / $this->lastStep * 100) }}%</flux:text>
        </div>
        <progress
            class="h-2 w-full overflow-hidden rounded-full bg-sage accent-moss dark:bg-zinc-800 dark:accent-sage"
            value="{{ $step }}"
            max="{{ $this->lastStep }}"
            aria-label="{{ __('Company profile progress') }}"
        >{{ (int) round($step / $this->lastStep * 100) }}%</progress>
        <nav class="mt-3 overflow-x-auto pb-1" aria-label="{{ __('Company profile steps') }}">
            <ol class="grid min-w-max grid-flow-col auto-cols-fr gap-2 text-xs sm:min-w-0">
                @foreach ($this->stepLabels as $stepNumber => $stepLabel)
                    <li
                        class="rounded-full px-3 py-1.5 text-center {{ $stepNumber === $step ? 'bg-moss font-semibold text-white dark:bg-sage dark:text-ink' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' }}"
                        @if ($stepNumber === $step) aria-current="step" @endif
                        wire:key="intake-step-{{ $stepNumber }}"
                    >
                        {{ $stepNumber }}. {{ $stepLabel }}
                    </li>
                @endforeach
            </ol>
        </nav>
    </div>

    <form wire:submit="{{ $step === $this->lastStep ? 'save' : 'next' }}" class="space-y-6">
        @if ($step === 1)
            <flux:input wire:model="name" :label="__('Business name')" :description="$this->isEstablishedTrack ? __('Enter the company’s current legal or public name.') : __('Leave blank if you have not named your business yet.')" type="text" autofocus />

            @if (! $this->isEstablishedTrack)
                <flux:input wire:model="desired_name" :label="__('Desired business name')" :description="__('If not formed yet, what name would you like to use?')" type="text" />
            @endif

            <flux:radio.group wire:model="dba_status" :label="__('Have you filed a DBA / assumed name?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" wire:key="dba-status-{{ $value }}" />
                @endforeach
            </flux:radio.group>
            <flux:input wire:model="industry" :label="__('Business category / industry')" :placeholder="__('e.g. Landscaping, consulting, retail')" type="text" />
            <flux:input wire:model="started_on" :label="__('Business start date')" :description="__('When did (or will) the business start? Leave blank if unsure.')" type="date" />
        @endif

        @if ((! $this->isEstablishedTrack && $step === 2) || ($this->isEstablishedTrack && $step === 1))
            <flux:radio.group wire:model.live="operates_in_texas" :label="__('Is the business located and operating in Texas?')">
                <flux:radio value="yes" :label="__('Yes')" />
                <flux:radio value="no" :label="__('No')" />
            </flux:radio.group>

            @if ($this->isBlockedOutsideTexas)
                <flux:callout icon="map-pin" variant="warning">
                    <flux:callout.heading>{{ __('Texas only for now') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Mentrovia currently supports Texas businesses only. Support for other states is planned — check back soon.') }}
                    </flux:callout.text>
                </flux:callout>
            @else
                <flux:input wire:model="city" :label="__('City')" type="text" />
                <flux:input wire:model="county" :label="__('County')" type="text" />
                <flux:select wire:model="location_type" :label="__('How does the business operate?')" :placeholder="__('Choose one...')">
                    @foreach (\App\Enums\LocationType::options() as $value => $label)
                        <flux:select.option :value="$value" wire:key="location-type-{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="address" :label="__('Physical address or operating area')" :description="__('Optional')" type="text" />
            @endif
        @endif

        @if ((! $this->isEstablishedTrack && $step === 3) || ($this->isEstablishedTrack && $step === 1))
            <flux:select wire:model="legal_structure" :label="__('Current legal structure')" :placeholder="__('Choose one...')">
                @foreach (\App\Enums\LegalStructure::options() as $value => $label)
                    <flux:select.option :value="$value" wire:key="legal-structure-{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        @if ((! $this->isEstablishedTrack && $step === 3) || ($this->isEstablishedTrack && $step === 2))
            <flux:input wire:model="owner_count" :label="__('Number of owners')" type="number" min="1" max="100" />
            <flux:input wire:model.live="employee_count" :label="__('Number of employees')" :description="__('Not counting owners or contractors.')" type="number" min="0" max="5000" />
            @if ((int) $employee_count > 0)
                <flux:input wire:model="first_employee_on" :label="__('First employee hire date')" :description="__('Leave blank if unsure.')" type="date" />
            @endif
            <flux:checkbox wire:model="uses_contractors" :label="__('We use independent contractors')" />
        @endif

        @if ((! $this->isEstablishedTrack && $step === 4) || ($this->isEstablishedTrack && $step === 2))
            <flux:radio.group wire:model="sells_taxable_goods" :label="__('Do you sell physical or taxable goods?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" wire:key="taxable-goods-{{ $value }}" />
                @endforeach
            </flux:radio.group>
            <flux:radio.group wire:model="sells_taxable_services" :label="__('Do you sell services that may be taxable in Texas?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" wire:key="taxable-services-{{ $value }}" />
                @endforeach
            </flux:radio.group>
            <flux:radio.group wire:model="has_sales_tax_permit" :label="__('Do you have a Texas sales tax permit?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" wire:key="sales-tax-permit-{{ $value }}" />
                @endforeach
            </flux:radio.group>
            <flux:radio.group wire:model="has_ein" :label="__('Do you have an EIN (federal tax ID)?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" wire:key="has-ein-{{ $value }}" />
                @endforeach
            </flux:radio.group>
        @endif

        @if ((! $this->isEstablishedTrack && $step === 4) || ($this->isEstablishedTrack && $step === 3))
            <flux:select wire:model="annual_revenue_range" :label="__('Estimated annual revenue')" :placeholder="__('Choose a range...')">
                @foreach (\App\Enums\AnnualRevenueRange::options() as $value => $label)
                    <flux:select.option :value="$value" wire:key="annual-revenue-{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="monthly_revenue_range" :label="__('Current monthly revenue')" :placeholder="__('Choose a range...')">
                @foreach (\App\Enums\MonthlyRevenueRange::options() as $value => $label)
                    <flux:select.option :value="$value" wire:key="monthly-revenue-{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="first_sale_on" :label="__('First sale date')" :description="__('Leave blank if you have not sold anything yet.')" type="date" />
        @endif

        @if ((! $this->isEstablishedTrack && $step === 5) || ($this->isEstablishedTrack && $step === 3))
            <div class="space-y-4">
                <flux:checkbox wire:model="has_business_bank" :label="__('We have a dedicated business bank account')" />
                <flux:checkbox wire:model="has_bookkeeping" :label="__('We use bookkeeping software or a bookkeeper')" />
                <flux:checkbox wire:model="has_payroll" :label="__('We have a payroll provider')" :disabled="$employee_count === 0" />
            </div>

            <flux:radio.group wire:model="filing_confidence" :label="__('How confident are you about your filings and setup?')">
                @foreach (\App\Enums\FilingConfidence::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" wire:key="filing-confidence-{{ $value }}" />
                @endforeach
            </flux:radio.group>

            <flux:separator />

            <div>
                <flux:heading size="lg">{{ __('Review') }}</flux:heading>
                <dl class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach ($this->reviewSummary as $label => $value)
                        <div class="flex justify-between gap-4 py-2" wire:key="review-{{ $loop->index }}">
                            <dt><flux:text size="sm">{{ $label }}</flux:text></dt>
                            <dd><flux:text size="sm" variant="strong">{{ $value }}</flux:text></dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        <div class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-wrap items-center gap-2">
                @if ($step > 1)
                    <flux:button type="button" variant="ghost" wire:click="back">{{ __('Back') }}</flux:button>
                @endif
                @if ($existingBusinessId === null)
                    <flux:button type="button" variant="ghost" wire:click="saveAndExit" wire:loading.attr="disabled" wire:target="saveAndExit">
                        {{ __('Save & exit') }}
                    </flux:button>
                @endif
            </div>
            <div>
                @if ($step < $this->lastStep)
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="next" data-testid="intake-continue">
                        {{ __('Continue') }}
                    </flux:button>
                @else
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ __('Save profile') }}</flux:button>
                @endif
            </div>
        </div>

        <div class="min-h-5" role="status" aria-live="polite">
            @if ($savedStatus !== '')
                <flux:text size="sm">{{ $savedStatus }}</flux:text>
            @endif
            <flux:text wire:dirty size="sm">{{ __('Unsaved changes') }}</flux:text>
            <flux:text wire:loading wire:target="next,save,saveAndExit" size="sm">{{ __('Saving…') }}</flux:text>
        </div>
    </form>

    <div class="mt-8">
        <x-advisory-disclaimer />
    </div>
</section>
