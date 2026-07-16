<x-layouts::app :title="__('Send feedback')">
    <section class="mx-auto w-full max-w-2xl">
        <div class="mb-8">
            <flux:heading size="xl">{{ __('Help improve Mentrovia') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Tell us about a problem, a source that needs attention, or an idea for the beta. Do not include passwords, account numbers, or other sensitive information.') }}
            </flux:text>
        </div>

        @if (session('status'))
            <flux:callout icon="check-circle" color="green" class="mb-6">
                <flux:callout.heading>{{ __('Feedback sent') }}</flux:callout.heading>
                <flux:callout.text>{{ session('status') }}</flux:callout.text>
            </flux:callout>
        @endif

        <form method="POST" action="{{ route('feedback.store') }}" class="space-y-6 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700 sm:p-6">
            @csrf

            <flux:field>
                <flux:label>{{ __('What kind of feedback is this?') }}</flux:label>
                <flux:select name="category" :value="old('category')" required>
                    <flux:select.option value="">{{ __('Choose one') }}</flux:select.option>
                    @foreach (App\Enums\FeedbackCategory::options() as $value => $label)
                        <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="category" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('What happened or what should change?') }}</flux:label>
                <flux:textarea
                    name="message"
                    rows="7"
                    required
                    :value="old('message')"
                    :placeholder="__('Include what you expected, what happened, and enough context for us to reproduce it.')"
                />
                <flux:description>{{ __('At least 10 characters. Please leave out sensitive information.') }}</flux:description>
                <flux:error name="message" />
            </flux:field>

            <input type="hidden" name="page" value="{{ old('page', $page) }}">

            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:button variant="ghost" :href="route('dashboard')" wire:navigate>
                    {{ __('Back to dashboard') }}
                </flux:button>
                <flux:button type="submit" variant="primary" icon="paper-airplane">
                    {{ __('Send feedback') }}
                </flux:button>
            </div>
        </form>
    </section>
</x-layouts::app>
