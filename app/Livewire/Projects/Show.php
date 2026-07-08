<?php

namespace App\Livewire\Projects;

use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoKind;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Enums\ProjectPermission;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Show extends Component
{
    use WithFileUploads;

    public Project $project;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $uploadDescription = '';

    /** @var array<int, int> */
    public array $selectedPhotoIds = [];

    public string $generationNotes = '';

    public string $shareEmail = '';

    public string $sharePermission = 'read';

    public ?int $galleryPhotoId = null;

    public string $galleryVariant = 'original';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    public function saveUploads(): void
    {
        $this->authorize('update', $this->project);

        $maxDimension = (int) config('photostudio.processing.max_source_dimension', 8000);

        $this->validate([
            'uploads' => ['required', 'array', 'min:1', 'max:12'],
            'uploads.*' => [
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.((int) config('photostudio.processing.max_upload_mb', 25) * 1024),
                Rule::dimensions()->maxWidth($maxDimension)->maxHeight($maxDimension),
            ],
            'uploadDescription' => ['nullable', 'string', 'max:2000'],
        ]);

        $disk = config('photostudio.disk');

        foreach ($this->uploads as $file) {
            $path = $file->storeAs(
                config('photostudio.uploaded_prefix').Str::uuid7(),
                'original.'.$file->extension(),
                ['disk' => $disk],
            );

            $photo = $this->project->photos()->create([
                'user_id' => Auth::id(),
                'kind' => PhotoKind::Uploaded,
                'disk' => $disk,
                'path' => $path,
                'processing_status' => PhotoProcessingStatus::Pending,
                'original_filename' => $file->getClientOriginalName(),
                'text' => $this->uploadDescription !== '' ? $this->uploadDescription : null,
                'text_source' => $this->uploadDescription !== '' ? PhotoTextSource::User : null,
            ]);

            // Derivative processing dispatches the auto-caption job itself
            // once the normalized LLM input exists.
            GeneratePhotoDerivatives::dispatch($photo);
        }

        $this->reset(['uploads', 'uploadDescription']);
        unset($this->uploadedPhotos);

        Flux::toast(__('Photos uploaded — optimizing in the background.'), variant: 'success');
    }

    public function openGallery(int $photoId): void
    {
        $photo = $this->project->generatedPhotos()->whereKey($photoId)->firstOrFail();

        $this->galleryPhotoId = $photo->id;

        $this->resetErrorBag();

        unset($this->galleryPhoto, $this->galleryVariants);

        $this->galleryVariant = array_key_exists('hero', $this->galleryVariants())
            ? 'hero'
            : (string) array_key_first($this->galleryVariants());

        Flux::modal('photo-gallery')->show();
    }

    public function selectGalleryVariant(string $variant): void
    {
        if (array_key_exists($variant, $this->galleryVariants())) {
            $this->galleryVariant = $variant;
        }
    }

    public function deleteGalleryPhoto(): void
    {
        $this->authorize('update', $this->project);

        $photo = $this->project->generatedPhotos()->whereKey($this->galleryPhotoId)->firstOrFail();

        // Every file for a photo (original + derivatives) lives in its own
        // per-photo directory, so removing that directory removes the set.
        Storage::disk($photo->disk)->deleteDirectory(dirname($photo->path));

        $photo->delete();

        $this->galleryPhotoId = null;

        unset($this->galleryPhoto, $this->galleryVariants, $this->batches);

        Flux::modal('photo-gallery')->close();

        Flux::toast(__('Image set deleted.'), variant: 'success');
    }

    #[Computed]
    public function galleryPhoto(): ?Photo
    {
        if ($this->galleryPhotoId === null) {
            return null;
        }

        return $this->project->generatedPhotos()->whereKey($this->galleryPhotoId)->first();
    }

    /**
     * The displayable versions of the gallery photo, keyed by variant, in
     * display order. Only versions that actually exist are listed.
     *
     * @return array<string, array{label: string, path: string, width: int|null, height: int|null, size_bytes: int|null}>
     */
    #[Computed]
    public function galleryVariants(): array
    {
        $photo = $this->galleryPhoto();

        if ($photo === null) {
            return [];
        }

        $variants = [
            'original' => [
                'label' => __('Original'),
                'path' => $photo->path,
                'width' => $photo->width,
                'height' => $photo->height,
                'size_bytes' => $photo->size_bytes !== null ? (int) $photo->size_bytes : null,
            ],
        ];

        $labels = [
            'master' => __('Master (WebP)'),
            'hero' => __('Hero (WebP)'),
            'hero-jpg' => __('Hero (JPG)'),
            'card' => __('Card (WebP)'),
            'thumb' => __('Thumbnail (WebP)'),
        ];

        foreach ($labels as $key => $label) {
            $info = $photo->derivatives[$key] ?? null;

            if (is_array($info)) {
                $variants[$key] = [
                    'label' => $label,
                    'path' => $info['path'],
                    'width' => $info['width'] ?? null,
                    'height' => $info['height'] ?? null,
                    'size_bytes' => isset($info['size_bytes']) ? (int) $info['size_bytes'] : null,
                ];
            }
        }

        return $variants;
    }

    public function retryProcessing(int $photoId): void
    {
        $this->authorize('update', $this->project);

        $photo = $this->project->photos()->whereKey($photoId)->firstOrFail();

        $photo->update([
            'processing_status' => PhotoProcessingStatus::Pending,
            'processing_error' => null,
        ]);

        GeneratePhotoDerivatives::dispatch($photo);

        unset($this->uploadedPhotos, $this->batches);

        Flux::toast(__('Reprocessing queued.'), variant: 'success');
    }

    public function toggleSelection(int $photoId): void
    {
        $this->selectedPhotoIds = in_array($photoId, $this->selectedPhotoIds, true)
            ? array_values(array_diff($this->selectedPhotoIds, [$photoId]))
            : [...$this->selectedPhotoIds, $photoId];
    }

    public function generate(): void
    {
        $this->authorize('update', $this->project);

        $this->validate([
            'generationNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $inputIds = $this->project->uploadedPhotos()
            ->whereKey($this->selectedPhotoIds)
            ->pluck('id');

        if ($inputIds->isEmpty()) {
            $this->addError('selectedPhotoIds', __('Select at least one uploaded photo to generate from.'));

            return;
        }

        $batch = $this->project->generationBatches()->create([
            'user_id' => Auth::id(),
            'status' => GenerationBatchStatus::Pending,
            'user_text' => $this->generationNotes !== '' ? $this->generationNotes : null,
            'input_photo_ids' => $inputIds->all(),
        ]);

        RunPhotoGenerationBatch::dispatch($batch);

        $this->reset(['selectedPhotoIds', 'generationNotes']);
        unset($this->batches);

        Flux::toast(__('Generation started — the top 3 best-value models are working on it.'), variant: 'success');
    }

    public function share(): void
    {
        $this->authorize('share', $this->project);

        $this->validate([
            'shareEmail' => ['required', 'email'],
            'sharePermission' => ['required', Rule::enum(ProjectPermission::class)],
        ]);

        $user = User::query()->where('email', $this->shareEmail)->first();

        if ($user === null) {
            $this->addError('shareEmail', __('No user exists with that email address.'));

            return;
        }

        if ($user->is(Auth::user())) {
            $this->addError('shareEmail', __('You already own this project.'));

            return;
        }

        $this->project->sharedUsers()->syncWithoutDetaching([
            $user->id => ['permission' => $this->sharePermission],
        ]);

        $this->reset(['shareEmail', 'sharePermission']);
        unset($this->sharedUsers);

        Flux::toast(__('Project shared with :name.', ['name' => $user->name]), variant: 'success');
    }

    public function unshare(int $userId): void
    {
        $this->authorize('share', $this->project);

        $this->project->sharedUsers()->detach($userId);

        unset($this->sharedUsers);
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->project->isEditableBy(Auth::user());
    }

    /**
     * @return Collection<int, Photo>
     */
    #[Computed]
    public function uploadedPhotos(): Collection
    {
        return $this->project->uploadedPhotos()->latest()->get();
    }

    /**
     * @return Collection<int, PhotoGenerationBatch>
     */
    #[Computed]
    public function batches(): Collection
    {
        return $this->project->generationBatches()
            ->with('generatedPhotos')
            ->latest()
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function sharedUsers(): Collection
    {
        return $this->project->sharedUsers()->get();
    }

    #[Computed]
    public function hasRunningBatches(): bool
    {
        return $this->batches()->contains(
            fn (PhotoGenerationBatch $batch) => ! $batch->isFinished()
        );
    }

    #[Computed]
    public function hasProcessingPhotos(): bool
    {
        return $this->project->photos()
            ->whereIn('processing_status', [PhotoProcessingStatus::Pending, PhotoProcessingStatus::Processing])
            ->exists();
    }
}
