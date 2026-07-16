<x-layouts::app :title="__('Grow')">
    <section class="mx-auto max-w-6xl">
        <div class="border-b border-ink/10 pb-8 dark:border-white/10">
            <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Growth workspace') }}</p>
            <h1 class="mt-4 max-w-[22ch] font-display text-5xl tracking-tight text-balance text-ink sm:text-6xl dark:text-white">{{ __('Move from a clear brand to usable creative.') }}</h1>
            <p class="mt-5 max-w-[52ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Build a foundation for :name, turn it into a focused campaign, then use your own project photos to create finished visual assets.', ['name' => $business->displayName()]) }}</p>
        </div>

        <ol role="list" class="mt-8 grid gap-x-8 @container lg:grid-cols-3">
            <li class="flex flex-col border-t border-ink/10 py-6 dark:border-white/10">
                <div>
                    <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('01 · Brand foundation') }}</p>
                    <h2 class="mt-3 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Find your voice') }}</h2>
                    <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $brandKit === null ? __('Create a first brand kit to define positioning, tone, names, and visual direction.') : __('Brand kit version :version is available as the foundation for your next campaign.', ['version' => $brandKit->version]) }}</p>
                </div>
                <div class="mt-6">
                    <flux:button :variant="$brandKit === null ? 'primary' : 'ghost'" size="sm" :href="route('branding')" wire:navigate icon="swatch">{{ $brandKit === null ? __('Create a brand kit') : __('Open Brand Studio') }}</flux:button>
                </div>
            </li>

            <li class="flex flex-col border-t border-ink/10 py-6 dark:border-white/10">
                <div>
                    <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('02 · Campaign plan') }}</p>
                    <h2 class="mt-3 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Plan a focused launch') }}</h2>
                    <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $advertisingKit === null ? __('Use your latest brand kit to generate channel-ready copy, an outline, and image directions.') : __('Advertising kit version :version is ready to guide campaign copy and image directions.', ['version' => $advertisingKit->version]) }}</p>
                </div>
                <div class="mt-6">
                    <flux:button :variant="$brandKit !== null && $advertisingKit === null ? 'primary' : 'ghost'" size="sm" :href="route('advertising')" wire:navigate icon="megaphone">{{ __('Open Campaign Planner') }}</flux:button>
                </div>
            </li>

            <li class="flex flex-col border-t border-ink/10 py-6 dark:border-white/10">
                <div>
                    <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('03 · Creative assets') }}</p>
                    <h2 class="mt-3 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Refine your own photos') }}</h2>
                    <p class="mt-3 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('Upload work-in-progress or source photos, choose a project, then generate cleaned-up variants with your creative brief.') }}</p>
                </div>
                <div class="mt-6">
                    <flux:button :variant="$advertisingKit !== null ? 'primary' : 'ghost'" size="sm" :href="route('projects.index')" wire:navigate icon="photo">{{ __('Open Photo Studio') }}</flux:button>
                </div>
            </li>
        </ol>

        @if ($advertisingKit !== null && $advertisingKit->image_prompts !== [])
            <div class="mt-8 border-t border-ink/10 pt-8 dark:border-white/10">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Creative brief handoff') }}</p>
                        <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Start a photo project with a campaign direction.') }}</h2>
                    </div>
                    <a href="{{ route('advertising') }}" wire:navigate class="text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ __('View all campaign output') }}</a>
                </div>
                <div class="mt-5 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                    @foreach ($advertisingKit->image_prompts as $prompt)
                        <div class="flex flex-wrap items-start justify-between gap-4 py-5">
                            <p class="min-w-0 max-w-[70ch] text-base/7 text-pretty text-muted dark:text-zinc-300">{{ $prompt }}</p>
                            <flux:button size="sm" variant="ghost" :href="route('projects.index', ['photo_brief' => $prompt])" wire:navigate icon="photo">{{ __('Use in Photo Studio') }}</flux:button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="mt-10 border-t border-ink/10 pt-8 dark:border-white/10">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="font-mono text-base font-medium tracking-wide text-moss sm:text-sm dark:text-sage">{{ __('Recent photo projects') }}</p>
                    <h2 class="mt-2 font-display text-3xl tracking-tight text-ink dark:text-white">{{ __('Your working assets') }}</h2>
                </div>
                <a href="{{ route('projects.index') }}" wire:navigate class="text-base font-medium text-moss underline decoration-moss/30 underline-offset-4 sm:text-sm dark:text-sage">{{ __('See all projects') }}</a>
            </div>
            <div class="mt-5 divide-y divide-ink/10 border-t border-ink/10 dark:divide-white/10 dark:border-white/10">
                @forelse ($projects as $project)
                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="flex items-center justify-between gap-4 py-4">
                        <span class="min-w-0">
                            <span class="block text-base font-semibold text-ink dark:text-white">{{ $project->name }}</span>
                            <span class="mt-1 block text-base/7 text-muted dark:text-zinc-300">{{ trans_choice(':count photo|:count photos', $project->photos_count, ['count' => $project->photos_count]) }} · {{ $project->project_date->format('M j, Y') }}</span>
                        </span>
                        <span class="shrink-0 text-base font-medium text-moss sm:text-sm dark:text-sage">{{ __('Open') }}</span>
                    </a>
                @empty
                    <p class="py-5 text-base/7 text-pretty text-muted dark:text-zinc-300">{{ __('No photo project yet. Start one when you have source photos ready to refine.') }}</p>
                @endforelse
            </div>
        </div>
    </section>
</x-layouts::app>
