<div class="flex h-full w-full flex-1 flex-col gap-8"
    @if ($this->hasRunningBatches || $this->hasProcessingPhotos) wire:poll.5s @endif>
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl">{{ $project->name }}</flux:heading>
                @unless ($project->isOwnedBy(auth()->user()))
                    <flux:badge size="sm" color="zinc">{{ __('Shared with you') }}</flux:badge>
                @endunless
            </div>
            <flux:text class="mt-1">{{ $project->project_date->format('F j, Y') }} · {{ __('Owner: :name', ['name' => $project->owner->name]) }}</flux:text>
        </div>

        <div class="flex items-center gap-2">
            @can('share', $project)
                <flux:modal.trigger name="share-project">
                    <flux:button icon="user-plus" variant="outline">{{ __('Share') }}</flux:button>
                </flux:modal.trigger>
            @endcan
            <flux:button :href="route('projects.index')" wire:navigate variant="ghost" icon="arrow-left">
                {{ __('All projects') }}
            </flux:button>
        </div>
    </div>

    {{-- Upload --}}
    @if ($this->canEdit)
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">{{ __('Upload photos') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Add photos of the dirty or in-progress state. If you leave the notes empty, a description is generated automatically from the image content.') }}
            </flux:text>

            <form wire:submit="saveUploads" class="mt-4 space-y-4">
                <flux:input type="file" wire:model="uploads" multiple accept="image/*" :label="__('Photos')" />

                <flux:textarea wire:model="uploadDescription" :label="__('Notes (optional)')" rows="2"
                    :placeholder="__('e.g. Product shots, still dusty from the workshop, background cluttered')" />

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">
                        {{ __('Upload') }}
                    </flux:button>
                    <div wire:loading wire:target="uploads">
                        <flux:text>{{ __('Preparing photos...') }}</flux:text>
                    </div>
                </div>
            </form>
        </div>
    @endif

    {{-- Uploaded photos + generate --}}
    <div>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg">{{ __('Uploaded photos') }}</flux:heading>
            <flux:text>{{ __('Select photos, then generate cleaned-up versions.') }}</flux:text>
        </div>

        @error('selectedPhotoIds')
            <flux:text class="mt-2 text-red-500">{{ $message }}</flux:text>
        @enderror

        @if ($aiError !== null)
            <flux:callout variant="danger" icon="exclamation-triangle" class="mt-3">
                <flux:callout.heading>{{ __('Generation could not start') }}</flux:callout.heading>
                <flux:callout.text>{{ $aiError }}</flux:callout.text>
                @if ($aiErrorShowsSettings)
                    <x-slot name="actions">
                        <flux:button size="sm" :href="route('ai.edit')" wire:navigate>
                            {{ __('Review AI settings') }}
                        </flux:button>
                    </x-slot>
                @endif
            </flux:callout>
        @endif

        @if ($this->uploadedPhotos->isEmpty())
            <div class="mt-4 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                <flux:text>{{ __('No photos uploaded yet.') }}</flux:text>
            </div>
        @else
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->uploadedPhotos as $photo)
                    <div wire:key="uploaded-{{ $photo->id }}"
                        class="overflow-hidden rounded-xl border {{ in_array($photo->id, $selectedPhotoIds, true) ? 'border-moss ring-2 ring-moss/30' : 'border-zinc-200 dark:border-zinc-700' }}">
                        <button type="button" wire:click="toggleSelection({{ $photo->id }})"
                            @disabled(! $this->canEdit) class="block w-full cursor-pointer">
                            <img src="{{ $photo->url('thumb') }}" alt="{{ $photo->text ?? $photo->original_filename }}"
                                class="aspect-square w-full object-cover" loading="lazy" decoding="async">
                        </button>
                        <div class="space-y-1 p-3">
                            <flux:text class="line-clamp-2 text-sm">
                                {{ $photo->text ?? __('Description pending...') }}
                            </flux:text>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($photo->processingFailed())
                                    <flux:badge size="sm" color="red">{{ __('Processing failed') }}</flux:badge>
                                    @if ($this->canEdit)
                                        <flux:button size="xs" variant="ghost" icon="arrow-path"
                                            wire:click="retryProcessing({{ $photo->id }})">
                                            {{ __('Retry') }}
                                        </flux:button>
                                    @endif
                                @elseif (! $photo->isProcessed())
                                    <flux:badge size="sm" color="amber">{{ __('Optimizing...') }}</flux:badge>
                                @endif
                                @if ($photo->text_source !== null)
                                    <flux:badge size="sm"
                                        :color="$photo->text_source === \App\Enums\PhotoTextSource::Auto ? 'purple' : 'zinc'">
                                        {{ $photo->text_source === \App\Enums\PhotoTextSource::Auto ? __('AI described') : __('User notes') }}
                                    </flux:badge>
                                @endif
                                <flux:text class="text-xs">{{ $photo->created_at->format('M j, Y') }}</flux:text>
                            </div>
                            @if ($photo->processingFailed() && $photo->processing_error)
                                <flux:text class="line-clamp-2 text-xs text-red-500">{{ $photo->processing_error }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($this->canEdit)
                <div class="mt-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <form wire:submit="generate" class="space-y-4">
                        <flux:textarea wire:model="generationNotes" rows="2"
                            :label="__('What should the finished result look like? (optional)')"
                            :placeholder="__('e.g. Clean product on a neutral background, bright even lighting')" />

                        <div class="flex items-center gap-3">
                            <flux:button type="submit" variant="primary" icon="sparkles"
                                :disabled="count($selectedPhotoIds) === 0">
                                {{ __('Generate with top 3 models') }}
                            </flux:button>
                            <flux:text>
                                {{ trans_choice(':count photo selected|:count photos selected', count($selectedPhotoIds)) }}
                            </flux:text>
                        </div>
                        <flux:callout icon="banknotes" color="amber" inline>
                            <flux:callout.text>
                                {{ __('This starts paid AI work: up to three image generations. The chooser caps each generated-image estimate at :cost; provider billing can vary.', ['cost' => '$'.number_format((float) config('photostudio.chooser.requirements.max_usd_per_image'), 2)]) }}
                            </flux:callout.text>
                        </flux:callout>
                    </form>
                </div>
            @endif
        @endif
    </div>

    {{-- Generated results --}}
    <div>
        <flux:heading size="lg">{{ __('Generated photos') }}</flux:heading>

        @if ($this->batches->isEmpty())
            <div class="mt-4 rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">
                <flux:text>{{ __('Nothing generated yet. Select uploaded photos above and generate.') }}</flux:text>
            </div>
        @else
            <div class="mt-4 space-y-6">
                @foreach ($this->batches as $batch)
                    <div wire:key="batch-{{ $batch->id }}"
                        class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <flux:badge size="sm" :color="match ($batch->status) {
                                    \App\Enums\GenerationBatchStatus::Completed => 'green',
                                    \App\Enums\GenerationBatchStatus::Failed => 'red',
                                    default => 'amber',
                                }">
                                    {{ $batch->status->label() }}
                                </flux:badge>
                                <flux:text class="text-sm">
                                    {{ $batch->created_at->format('M j, Y g:i A') }}
                                    · {{ trans_choice(':count input photo|:count input photos', count($batch->input_photo_ids ?? [])) }}
                                </flux:text>
                            </div>
                            @if ($batch->user_text)
                                <flux:text class="text-sm italic">"{{ Str::limit($batch->user_text, 80) }}"</flux:text>
                            @endif
                        </div>

                        @if ($batch->status === \App\Enums\GenerationBatchStatus::Failed && $batch->error)
                            <flux:text class="mt-3 text-sm text-red-500">{{ $batch->error }}</flux:text>
                        @elseif (! $batch->isFinished())
                            <div class="mt-3 flex items-center gap-2">
                                <flux:icon.loading class="size-4" />
                                <flux:text class="text-sm">{{ __('Analyzing photos and generating with the selected models...') }}</flux:text>
                            </div>
                        @endif

                        @if ($batch->generatedPhotos->isNotEmpty())
                            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($batch->generatedPhotos as $photo)
                                    <div wire:key="generated-{{ $photo->id }}"
                                        class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                                        <button type="button" wire:click="openGallery({{ $photo->id }})"
                                            class="block w-full cursor-pointer">
                                            <picture>
                                                <source type="image/webp" srcset="{{ $photo->url('card') }}">
                                                <img src="{{ $photo->url('hero-jpg') }}" alt="{{ $photo->text }}"
                                                    class="aspect-square w-full object-cover" loading="lazy"
                                                    decoding="async">
                                            </picture>
                                        </button>
                                        <div class="space-y-1 p-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <flux:text class="text-sm font-medium">{{ $photo->model }}</flux:text>
                                                @if ($photo->processingFailed())
                                                    <flux:badge size="sm" color="red">{{ __('Optimization failed') }}</flux:badge>
                                                    @if ($this->canEdit)
                                                        <flux:button size="xs" variant="ghost" icon="arrow-path"
                                                            wire:click="retryProcessing({{ $photo->id }})">
                                                            {{ __('Retry') }}
                                                        </flux:button>
                                                    @endif
                                                @elseif (! $photo->isProcessed())
                                                    <flux:badge size="sm" color="amber">{{ __('Optimizing...') }}</flux:badge>
                                                @endif
                                            </div>
                                            <flux:text class="text-xs">
                                                {{ $photo->provider }}
                                                · {{ $photo->mode?->label() }}
                                                · ${{ number_format((float) $photo->cost_usd, 3) }}
                                                {{ $photo->cost_source === \App\Enums\PhotoCostSource::Provider ? __('actual') : __('est.') }}
                                                · {{ $photo->created_at->format('M j, Y') }}
                                            </flux:text>
                                            @if ($photo->isProcessed())
                                                <flux:text class="text-xs">
                                                    {{ collect(['hero', 'card', 'thumb'])
                                                        ->map(fn ($variant) => ($bytes = $photo->derivativeSizeBytes($variant)) !== null
                                                            ? $variant.' '.Illuminate\Support\Number::fileSize($bytes)
                                                            : null)
                                                        ->filter()
                                                        ->implode(' · ') }}
                                                </flux:text>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Generated photo gallery modal --}}
    <flux:modal name="photo-gallery" class="w-full max-w-4xl">
        @if ($this->galleryPhoto)
            @php($galleryPhoto = $this->galleryPhoto)
            @php($variants = $this->galleryVariants)
            @php($current = $variants[$galleryVariant] ?? null)

            <div class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ $galleryPhoto->model }}</flux:heading>
                    <flux:text class="mt-1 text-sm">
                        {{ $galleryPhoto->provider }}
                        · {{ $galleryPhoto->mode?->label() }}
                        · ${{ number_format((float) $galleryPhoto->cost_usd, 3) }}
                        {{ $galleryPhoto->cost_source === \App\Enums\PhotoCostSource::Provider ? __('actual') : __('est.') }}
                        · {{ $galleryPhoto->created_at->format('M j, Y g:i A') }}
                    </flux:text>
                </div>

                {{-- Main image --}}
                <div class="flex items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-900">
                    <img src="{{ $galleryPhoto->url($galleryVariant === 'original' ? null : $galleryVariant) }}"
                        alt="{{ $galleryPhoto->text }}" decoding="async"
                        class="max-h-[60vh] w-auto rounded-xl object-contain">
                </div>

                @if ($current)
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:text class="text-sm font-medium">
                            {{ $current['label'] }}
                            @if ($current['width'] && $current['height'])
                                · {{ $current['width'] }}×{{ $current['height'] }}
                            @endif
                            @if ($current['size_bytes'] !== null)
                                · {{ Illuminate\Support\Number::fileSize($current['size_bytes']) }}
                            @endif
                        </flux:text>

                        <flux:button as="a" size="sm" variant="primary" icon="arrow-down-tray"
                            href="{{ $galleryPhoto->downloadUrl($galleryVariant === 'original' ? null : $galleryVariant) }}"
                            download>
                            {{ __('Download :variant', ['variant' => $current['label']]) }}
                        </flux:button>
                    </div>
                @endif

                {{-- Version strip --}}
                <div class="flex gap-3 overflow-x-auto pb-1">
                    @foreach ($variants as $key => $variant)
                        <button type="button" wire:key="gallery-variant-{{ $key }}"
                            wire:click="selectGalleryVariant('{{ $key }}')"
                            class="w-28 shrink-0 cursor-pointer overflow-hidden rounded-lg border text-left transition {{ $galleryVariant === $key ? 'border-moss ring-2 ring-moss/30' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700 dark:hover:border-zinc-500' }}">
                            <img src="{{ $galleryPhoto->url($key === 'original' ? null : $key) }}"
                                alt="{{ $variant['label'] }}" loading="lazy" decoding="async"
                                class="aspect-square w-full object-cover">
                            <div class="p-1.5">
                                <flux:text class="block truncate text-xs font-medium">{{ $variant['label'] }}</flux:text>
                                @if ($variant['size_bytes'] !== null)
                                    <flux:text class="block text-xs">
                                        {{ Illuminate\Support\Number::fileSize($variant['size_bytes']) }}
                                    </flux:text>
                                @endif
                            </div>
                        </button>
                    @endforeach
                </div>

                {{-- Actions --}}
                @if ($this->canEdit)
                    <div class="border-t border-zinc-200 pt-3 dark:border-zinc-700">
                        <flux:button size="sm" variant="danger" icon="trash"
                            wire:click="deleteGalleryPhoto"
                            wire:confirm="{{ __('Delete this image set? The image and every version of it will be permanently removed from storage.') }}">
                            {{ __('Delete image set') }}
                        </flux:button>
                    </div>
                @endif

                {{-- Download links --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    <flux:text class="text-xs font-medium">{{ __('Download:') }}</flux:text>
                    @foreach ($variants as $key => $variant)
                        <a href="{{ $galleryPhoto->downloadUrl($key === 'original' ? null : $key) }}" download
                            class="text-xs text-blue-600 hover:underline dark:text-blue-400">
                            {{ $variant['label'] }}
                            @if ($variant['size_bytes'] !== null)
                                ({{ Illuminate\Support\Number::fileSize($variant['size_bytes']) }})
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Share modal --}}
    @can('share', $project)
        <flux:modal name="share-project" class="w-full max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Share project') }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Invite someone by email. They get access only after signing in with that verified address and accepting. Viewers can browse photos; editors can also upload and generate.') }}
                    </flux:text>
                </div>

                <form wire:submit="share" class="space-y-4">
                    <flux:input type="email" wire:model="shareEmail" :label="__('User email')"
                        :placeholder="__('teammate@example.com')" required />

                    <flux:select wire:model="sharePermission" :label="__('Permission')">
                        @foreach (\App\Enums\ProjectPermission::options() as $value => $label)
                            <flux:select.option :value="$value">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="flex justify-end">
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="share">
                            {{ __('Send invitation') }}
                        </flux:button>
                    </div>
                </form>

                @if ($this->pendingInvitations->isNotEmpty())
                    <div class="space-y-2">
                        <flux:heading size="sm">{{ __('Pending invitations') }}</flux:heading>
                        @foreach ($this->pendingInvitations as $invitation)
                            <div wire:key="invitation-{{ $invitation->public_id }}" class="flex items-center justify-between gap-2">
                                <div>
                                    <flux:text class="text-sm font-medium">{{ $invitation->email }}</flux:text>
                                    <flux:text class="text-xs">
                                        {{ $invitation->permission->label() }} · {{ __('Expires :date', ['date' => $invitation->expires_at->toFormattedDateString()]) }}
                                    </flux:text>
                                </div>
                                <flux:button size="sm" variant="ghost" icon="x-mark"
                                    :aria-label="__('Revoke invitation for :email', ['email' => $invitation->email])"
                                    wire:loading.attr="disabled" wire:target="revokeInvitation('{{ $invitation->public_id }}')"
                                    wire:click="revokeInvitation('{{ $invitation->public_id }}')" />
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($this->sharedUsers->isNotEmpty())
                    <div class="space-y-2">
                        <flux:heading size="sm">{{ __('Shared with') }}</flux:heading>
                        @foreach ($this->sharedUsers as $user)
                            <div wire:key="share-{{ $user->id }}" class="flex items-center justify-between gap-2">
                                <div>
                                    <flux:text class="text-sm font-medium">{{ $user->name }}</flux:text>
                                    <flux:text class="text-xs">
                                        {{ $user->email }} · {{ \App\Enums\ProjectPermission::tryFrom($user->pivot->permission)?->label() }}
                                    </flux:text>
                                </div>
                                <flux:button size="sm" variant="ghost" icon="x-mark"
                                    wire:click="unshare({{ $user->id }})" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </flux:modal>
    @endcan
</div>
