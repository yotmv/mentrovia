<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Photo Projects') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Upload photos of dirty or in-progress work and generate cleaned-up, finished versions with AI.') }}
            </flux:text>
        </div>

        <flux:modal.trigger name="create-project">
            <flux:button variant="primary" icon="plus">{{ __('New project') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:input type="search" wire:model.live.debounce.300ms="search" icon="magnifying-glass"
        :placeholder="__('Search projects by name or date...')" class="max-w-md" />

    @if ($this->projects->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
            <flux:heading size="lg">{{ __('No projects found') }}</flux:heading>
            <flux:text class="mt-2">
                {{ $search === '' ? __('Create your first project to start uploading photos.') : __('No project matches your search.') }}
            </flux:text>
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->projects as $project)
                <a href="{{ route('projects.show', $project) }}" wire:navigate wire:key="project-{{ $project->id }}"
                    class="group rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500">
                    <div class="flex items-start justify-between gap-2">
                        <flux:heading size="lg" class="group-hover:underline">{{ $project->name }}</flux:heading>
                        @unless ($project->isOwnedBy(auth()->user()))
                            <flux:badge size="sm" color="blue">{{ __('Shared') }}</flux:badge>
                        @endunless
                    </div>
                    <flux:text class="mt-2 text-sm">
                        {{ $project->project_date->format('M j, Y') }}
                        · {{ trans_choice(':count photo|:count photos', $project->photos_count) }}
                        @unless ($project->isOwnedBy(auth()->user()))
                            · {{ __('by :name', ['name' => $project->owner->name]) }}
                        @endunless
                    </flux:text>
                </a>
            @endforeach
        </div>

        <div>
            {{ $this->projects->links() }}
        </div>
    @endif

    <flux:modal name="create-project" class="w-full max-w-md">
        <form wire:submit="createProject" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('New project') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Group related photos under a name and date.') }}</flux:text>
            </div>

            <flux:input wire:model="name" :label="__('Name')" required autofocus
                :placeholder="__('Storefront photo refresh')" />

            <flux:input type="date" wire:model="projectDate" :label="__('Date')" required />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Create project') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
