<section class="mx-auto w-full max-w-2xl">
    <div class="mb-8">
        <flux:heading size="xl">{{ __('Company profile') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Tell us about your business so Mentrovia can build your personalized roadmap and task list.') }}
        </flux:text>
    </div>

    <div class="mb-6">
        <div class="mb-2 flex items-center justify-between">
            <flux:text size="sm" variant="strong">
                {{ __('Step :current of :total', ['current' => $step, 'total' => $this::LAST_STEP]) }}:
                {{ $this->stepLabels[$step] }}
            </flux:text>
            <flux:text size="sm">{{ (int) round($step / $this::LAST_STEP * 100) }}%</flux:text>
        </div>
        <div class="h-2 w-full rounded-full bg-zinc-200 dark:bg-zinc-700">
            <div class="h-2 rounded-full bg-zinc-800 transition-all dark:bg-zinc-200" style="width: {{ $step / $this::LAST_STEP * 100 }}%"></div>
        </div>
    </div>

    <form wire:submit="{{ $step === $this::LAST_STEP ? 'save' : 'next' }}" class="space-y-6">
        @if ($step === 1)
            <flux:input wire:model="name" :label="__('Business name')" :description="__('Leave blank if you have not named your business yet.')" type="text" autofocus />
            <flux:input wire:model="desired_name" :label="__('Desired business name')" :description="__('If not formed yet, what name would you like to use?')" type="text" />
            <flux:radio.group wire:model="dba_status" :label="__('Have you filed a DBA / assumed name?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>
            <flux:input wire:model="industry" :label="__('Business category / industry')" :placeholder="__('e.g. Landscaping, consulting, retail')" type="text" />
            <flux:input wire:model="started_on" :label="__('Business start date')" :description="__('When did (or will) the business start? Leave blank if unsure.')" type="date" />
        @endif

        @if ($step === 2)
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
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="address" :label="__('Physical address or operating area')" :description="__('Optional')" type="text" />
            @endif
        @endif

        @if ($step === 3)
            <flux:select wire:model="legal_structure" :label="__('Current legal structure')" :placeholder="__('Choose one...')">
                @foreach (\App\Enums\LegalStructure::options() as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="owner_count" :label="__('Number of owners')" type="number" min="1" max="100" />
            <flux:input wire:model.live="employee_count" :label="__('Number of employees')" :description="__('Not counting owners or contractors.')" type="number" min="0" max="5000" />
            @if ((int) $employee_count > 0)
                <flux:input wire:model="first_employee_on" :label="__('First employee hire date')" :description="__('Leave blank if unsure.')" type="date" />
            @endif
            <flux:checkbox wire:model="uses_contractors" :label="__('We use independent contractors')" />
        @endif

        @if ($step === 4)
            <flux:radio.group wire:model="sells_taxable_goods" :label="__('Do you sell physical or taxable goods?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>
            <flux:radio.group wire:model="sells_taxable_services" :label="__('Do you sell services that may be taxable in Texas?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>
            <flux:radio.group wire:model="has_sales_tax_permit" :label="__('Do you have a Texas sales tax permit?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>
            <flux:radio.group wire:model="has_ein" :label="__('Do you have an EIN (federal tax ID)?')">
                @foreach (\App\Enums\YesNoUnsure::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>
            <flux:select wire:model="annual_revenue_range" :label="__('Estimated annual revenue')" :placeholder="__('Choose a range...')">
                @foreach (\App\Enums\AnnualRevenueRange::options() as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="monthly_revenue_range" :label="__('Current monthly revenue')" :placeholder="__('Choose a range...')">
                @foreach (\App\Enums\MonthlyRevenueRange::options() as $value => $label)
                    <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="first_sale_on" :label="__('First sale date')" :description="__('Leave blank if you have not sold anything yet.')" type="date" />
        @endif

        @if ($step === 5)
            <div class="space-y-4">
                <flux:checkbox wire:model="has_business_bank" :label="__('We have a dedicated business bank account')" />
                <flux:checkbox wire:model="has_bookkeeping" :label="__('We use bookkeeping software or a bookkeeper')" />
                <flux:checkbox wire:model="has_payroll" :label="__('We have a payroll provider')" />
            </div>

            <flux:radio.group wire:model="filing_confidence" :label="__('How confident are you about your filings and setup?')">
                @foreach (\App\Enums\FilingConfidence::options() as $value => $label)
                    <flux:radio :value="$value" :label="$label" />
                @endforeach
            </flux:radio.group>

            <flux:separator />

            <div>
                <flux:heading size="lg">{{ __('Review') }}</flux:heading>
                <dl class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach ($this->reviewSummary as $label => $value)
                        <div class="flex justify-between gap-4 py-2">
                            <dt><flux:text size="sm">{{ $label }}</flux:text></dt>
                            <dd><flux:text size="sm" variant="strong">{{ $value }}</flux:text></dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        <div class="flex items-center justify-between pt-2">
            <div>
                @if ($step > 1)
                    <flux:button type="button" variant="ghost" wire:click="back">{{ __('Back') }}</flux:button>
                @endif
            </div>
            <div>
                @if ($step < $this::LAST_STEP)
                    <flux:button type="submit" variant="primary" :disabled="$step === 2 && $this->isBlockedOutsideTexas">
                        {{ __('Continue') }}
                    </flux:button>
                @else
                    <flux:button type="submit" variant="primary">{{ __('Save profile') }}</flux:button>
                @endif
            </div>
        </div>
    </form>

    <div class="mt-8">
        <x-advisory-disclaimer />
    </div>
</section>
