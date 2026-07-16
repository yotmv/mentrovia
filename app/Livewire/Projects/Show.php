<?php

namespace App\Livewire\Projects;

use App\Actions\Projects\CreateProjectInvitation;
use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoKind;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Enums\ProjectPermission;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoStorageCleanupService;
use App\Services\PhotoWorkReconciler;
use App\Support\Ai\AiFailurePresentation;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class Show extends Component
{
    use WithFileUploads;

    public Project $project;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public string $uploadDescription = '';

    /** @var array<int, mixed> */
    public array $selectedPhotoIds = [];

    public string $generationNotes = '';

    public ?string $aiError = null;

    public bool $aiErrorShowsSettings = false;

    public string $shareEmail = '';

    public string $sharePermission = 'read';

    public ?int $galleryPhotoId = null;

    public string $galleryVariant = 'original';

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;

        $brief = request()->query('photo_brief');

        if (is_string($brief) && mb_strlen($brief) <= 2000) {
            $this->generationNotes = $brief;
        }
    }

    public function saveUploads(
        PhotoGenerationLifecycle $lifecycle,
        PhotoStorageCleanupService $cleanupService,
        PhotoWorkReconciler $workReconciler,
    ): void {
        $this->authorize('update', $this->project);
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $canUseWorkspaceAi = $user->can('useAi', $this->project);
        $this->aiError = null;
        $this->aiErrorShowsSettings = false;

        $maxDimension = (int) config('photostudio.processing.max_source_dimension', 8000);

        $this->validate([
            'uploads' => ['required', 'array', 'min:1', 'max:12'],
            'uploads.*' => [
                'image',
                'mimes:jpg,jpeg,png',
                'max:'.((int) config('photostudio.processing.max_upload_mb', 25) * 1024),
                Rule::dimensions()->maxWidth($maxDimension)->maxHeight($maxDimension),
            ],
            'uploadDescription' => [Rule::requiredIf(! $canUseWorkspaceAi), 'nullable', 'string', 'max:2000'],
        ]);

        $disk = config('photostudio.disk');
        $lease = $lifecycle->acquireForProject($this->project, $user, 'photo-upload');

        if ($lease === null) {
            $this->addError('uploads', __('This project is no longer available for uploads.'));

            return;
        }

        $uploadFailed = false;

        try {
            foreach ($this->uploads as $file) {
                if (! $lifecycle->leaseIsUsable($lease)) {
                    $uploadFailed = true;
                    $this->addError('uploads', __('The upload was stopped because the account or project is no longer active.'));

                    break;
                }

                $directory = (string) config('photostudio.uploaded_prefix').Str::uuid7();
                $filename = 'original.'.$file->extension();
                $path = $directory.'/'.$filename;
                $provisionalPersistenceFailed = false;
                try {
                    $provisionalPhoto = $lifecycle->withUsableLease($lease, function () use ($path, $file, $disk, $user): ?Photo {
                        $project = Project::query()->find($this->project->id);

                        if ($project === null) {
                            return null;
                        }

                        return $project->photos()->create([
                            'account_id' => $project->account_id,
                            'user_id' => $user->id,
                            'kind' => PhotoKind::Uploaded,
                            'disk' => $disk,
                            'path' => $path,
                            'processing_status' => PhotoProcessingStatus::Pending,
                            'processing_error' => 'upload_in_progress',
                            'derivatives_enqueued_at' => now(),
                            'original_filename' => $file->getClientOriginalName(),
                            'text' => $this->uploadDescription !== '' ? $this->uploadDescription : null,
                            'text_source' => $this->uploadDescription !== '' ? PhotoTextSource::User : null,
                        ]);
                    });
                } catch (Throwable $exception) {
                    $provisionalPhoto = Photo::query()
                        ->where('project_id', $this->project->id)
                        ->where('user_id', $user->id)
                        ->where('disk', $disk)
                        ->where('path', $path)
                        ->where('processing_error', 'upload_in_progress')
                        ->first();

                    if (! $provisionalPhoto instanceof Photo) {
                        $provisionalPersistenceFailed = true;
                        Log::warning('Upload intent persistence failed.', [
                            'project_id' => $this->project->id,
                            'exception_class' => $exception::class,
                        ]);
                    }
                }

                if (! $provisionalPhoto instanceof Photo) {
                    $uploadFailed = true;
                    $this->addError('uploads', $provisionalPersistenceFailed
                        ? __('The upload could not be saved. Please try again.')
                        : __('The upload was stopped because the account or project is no longer active.'));

                    break;
                }

                try {
                    $storedPath = $file->storeAs($directory, $filename, ['disk' => $disk]);

                    if ($storedPath !== $path) {
                        throw new RuntimeException('The uploaded object path did not match its durable metadata.');
                    }

                    $photo = $lifecycle->withUsableLease($lease, function () use ($provisionalPhoto, $workReconciler): ?Photo {
                        $photo = Photo::query()->lockForUpdate()->find($provisionalPhoto->id);

                        if ($photo === null || $photo->processing_error !== 'upload_in_progress') {
                            return null;
                        }

                        $photo->update([
                            'processing_error' => null,
                            'derivatives_enqueued_at' => null,
                        ]);

                        $workReconciler->schedulePhoto($photo);

                        return $photo;
                    });
                } catch (Throwable $exception) {
                    try {
                        $committedPhoto = Photo::query()
                            ->where('project_id', $this->project->id)
                            ->where('user_id', $user->id)
                            ->where('disk', $disk)
                            ->where('path', $path)
                            ->first();
                        $stagedSlotReferencesPath = PhotoGenerationSlot::query()
                            ->where('staged_disk', $disk)
                            ->where('staged_path', $path)
                            ->exists();
                    } catch (Throwable $readException) {
                        $uploadFailed = true;

                        Log::critical('Uploaded photo commit state could not be verified; the object was retained for manual reconciliation.', [
                            'project_id' => $this->project->id,
                            'path_sha256' => hash('sha256', $path),
                            'exception_class' => $readException::class,
                        ]);

                        $this->addError('uploads', __('The upload status could not be verified. The file was retained safely for support review.'));

                        break;
                    }

                    if ($committedPhoto instanceof Photo && $committedPhoto->processing_error !== 'upload_in_progress') {
                        Log::warning('Uploaded photo commit acknowledgement was ambiguous but committed metadata was recovered.', [
                            'project_id' => $this->project->id,
                            'photo_id' => $committedPhoto->id,
                            'exception_class' => $exception::class,
                        ]);

                        continue;
                    }

                    $uploadFailed = true;

                    if (! $stagedSlotReferencesPath) {
                        $cleanupService->deleteOrTrack($disk, [$path]);
                    }

                    $lifecycle->withUsableLease($lease, function () use ($provisionalPhoto): void {
                        Photo::query()
                            ->whereKey($provisionalPhoto->id)
                            ->where('processing_error', 'upload_in_progress')
                            ->delete();
                    });

                    Log::warning('Uploaded photo persistence failed.', [
                        'project_id' => $this->project->id,
                        'object_retained' => $stagedSlotReferencesPath,
                        'exception_class' => $exception::class,
                    ]);

                    $this->addError('uploads', __('The upload could not be saved. Please try again.'));

                    break;
                }

                if (! $photo instanceof Photo) {
                    $uploadFailed = true;
                    $cleanupService->deleteOrTrack($disk, [$path]);
                    $this->addError('uploads', __('The upload was stopped because the account or project is no longer active.'));

                    break;
                }

            }
        } finally {
            $lifecycle->finish($lease);
        }

        $this->reset(['uploads', 'uploadDescription']);
        unset($this->uploadedPhotos);

        if ($uploadFailed) {
            return;
        }

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

    public function deleteGalleryPhoto(
        PhotoGenerationLifecycle $lifecycle,
        PhotoStorageCleanupService $cleanupService,
    ): void {
        $this->authorize('update', $this->project);
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $photo = $this->project->generatedPhotos()->whereKey($this->galleryPhotoId)->firstOrFail();
        $lease = $lifecycle->acquireForPhoto($photo, 'photo-delete', $user);

        if ($lease === null) {
            return;
        }

        try {
            $cleanupIds = $lifecycle->withUsableLease($lease, function () use ($photo, $cleanupService): ?array {
                $lockedPhoto = Photo::query()->lockForUpdate()->find($photo->id);

                if ($lockedPhoto === null) {
                    return null;
                }

                $paths = collect($lockedPhoto->derivatives ?? [])
                    ->pluck('path')
                    ->push($lockedPhoto->path)
                    ->filter()
                    ->values()
                    ->all();
                $cleanupIds = $cleanupService->recordMany($lockedPhoto->disk, $paths)->pluck('id')->all();

                $lockedPhoto->delete();

                return $cleanupIds;
            });

            if (! is_array($cleanupIds)) {
                return;
            }

            if (! $cleanupService->deleteRecorded($cleanupIds)) {
                $this->addError('gallery', __('The image files could not be deleted. Deletion will be retried.'));
            }
        } finally {
            $lifecycle->finish($lease);
        }

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

    public function retryProcessing(
        int $photoId,
        PhotoGenerationLifecycle $lifecycle,
        PhotoWorkReconciler $workReconciler,
    ): void {
        $this->authorize('update', $this->project);
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        $photo = $this->project->photos()->whereKey($photoId)->firstOrFail();
        $lease = $lifecycle->acquireForPhoto($photo, 'photo-derivative-retry', $user);

        if ($lease === null) {
            return;
        }

        try {
            $lifecycle->withUsableLease($lease, function () use ($photo, $workReconciler): bool {
                $updated = Photo::query()->whereKey($photo->id)->update([
                    'processing_status' => PhotoProcessingStatus::Pending,
                    'processing_error' => null,
                    'derivatives_enqueued_at' => null,
                ]) === 1;

                if ($updated) {
                    $workReconciler->schedulePhoto($photo);
                }

                return $updated;
            });
        } catch (Throwable $exception) {
            Log::warning('Photo derivative retry could not be enqueued.', [
                'photo_id' => $photo->id,
                'exception_class' => $exception::class,
            ]);
            $this->addError('processing', __('Reprocessing could not be queued. Please try again.'));

            return;
        } finally {
            $lifecycle->finish($lease);
        }

        unset($this->uploadedPhotos, $this->batches);

        Flux::toast(__('Reprocessing queued.'), variant: 'success');
    }

    public function toggleSelection(int $photoId): void
    {
        $maximum = max(1, min(100, (int) config('photostudio.max_batch_inputs', 12)));

        if (! in_array($photoId, $this->selectedPhotoIds, true) && count($this->selectedPhotoIds) >= $maximum) {
            $this->addError('selectedPhotoIds', __('You can select up to :count photos per generation.', ['count' => $maximum]));

            return;
        }

        $this->selectedPhotoIds = in_array($photoId, $this->selectedPhotoIds, true)
            ? array_values(array_diff($this->selectedPhotoIds, [$photoId]))
            : [...$this->selectedPhotoIds, $photoId];
    }

    public function generate(
        PhotoGenerationLifecycle $lifecycle,
        PhotoWorkReconciler $workReconciler,
    ): void {
        $this->authorize('useAi', $this->project);

        $this->aiError = null;
        $this->aiErrorShowsSettings = false;

        $this->validate([
            'generationNotes' => ['nullable', 'string', 'max:2000'],
            'selectedPhotoIds' => ['required', 'array', 'min:1', 'max:'.max(1, min(100, (int) config('photostudio.max_batch_inputs', 12)))],
            'selectedPhotoIds.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        if (collect($this->selectedPhotoIds)->contains(fn (mixed $id): bool => ! is_int($id))) {
            $this->addError('selectedPhotoIds', __('Every selected photo identifier must be an integer.'));

            return;
        }

        $inputs = $this->project->uploadedPhotos()
            ->whereKey($this->selectedPhotoIds)
            ->get(['id', 'user_id']);
        $inputIds = $inputs->pluck('id');

        if ($inputIds->count() !== count($this->selectedPhotoIds)) {
            $this->addError('selectedPhotoIds', __('Every selected photo must be an uploaded photo owned by this project.'));

            return;
        }

        $user = Auth::user();

        abort_unless($user instanceof User, 401);

        try {
            $lease = $lifecycle->acquireForProject(
                $this->project,
                $user,
                'batch-create',
                $inputs->pluck('user_id')->all(),
            );
        } catch (Throwable $exception) {
            $failure = AiFailurePresentation::fromException($exception);
            $this->aiError = $failure->message;
            $this->aiErrorShowsSettings = $failure->showsSettingsAction;

            return;
        }

        if ($lease === null) {
            $this->addError('selectedPhotoIds', __('Generation could not start because the account or project is no longer active.'));

            return;
        }

        try {
            $batch = $lifecycle->withUsableLease($lease, function () use ($user, $inputIds, $workReconciler): ?PhotoGenerationBatch {
                $project = Project::query()->find($this->project->id);

                $batch = $project?->generationBatches()->create([
                    'account_id' => $project->account_id,
                    'user_id' => $user->id,
                    'status' => GenerationBatchStatus::Pending,
                    'user_text' => $this->generationNotes !== '' ? $this->generationNotes : null,
                    'input_photo_ids' => $inputIds->all(),
                ]);

                if ($batch instanceof PhotoGenerationBatch) {
                    $workReconciler->scheduleBatch($batch);
                }

                return $batch;
            });

            if (! $batch instanceof PhotoGenerationBatch) {
                return;
            }
        } catch (Throwable $exception) {
            Log::warning('Photo generation batch could not be enqueued.', [
                'project_id' => $this->project->id,
                'exception_class' => $exception::class,
            ]);
            $failure = AiFailurePresentation::fromException($exception);
            $this->aiError = $failure->message;
            $this->aiErrorShowsSettings = $failure->showsSettingsAction;

            return;
        } finally {
            $lifecycle->finish($lease);
        }

        $this->reset(['selectedPhotoIds', 'generationNotes']);
        unset($this->batches);

        Flux::toast(__('Generation started — the top 3 best-value models are working on it.'), variant: 'success');
    }

    public function share(CreateProjectInvitation $createProjectInvitation): void
    {
        $this->authorize('share', $this->project);

        $this->shareEmail = ProjectInvitation::normalizeEmail($this->shareEmail);

        $this->validate([
            'shareEmail' => ['required', 'email', 'max:254'],
            'sharePermission' => ['required', Rule::enum(ProjectPermission::class)],
        ]);

        $createProjectInvitation->handle(
            $this->project,
            Auth::user(),
            $this->shareEmail,
            ProjectPermission::from($this->sharePermission),
        );

        $this->reset(['shareEmail', 'sharePermission']);
        unset($this->pendingInvitations);

        Flux::toast(__('Invitation sent. The recipient must sign in with that email address to accept it.'), variant: 'success');
    }

    public function revokeInvitation(string $publicId, AccountMutationGate $accountMutationGate): void
    {
        $this->authorize('share', $this->project);
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($publicId, $accountMutationGate, $user): void {
            $project = $accountMutationGate->lockProjectManagerOrFail(
                $this->project->account_id,
                $this->project->id,
                $user->id,
            );
            $invitation = $project->invitations()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            abort_if($invitation === null, 404);
            abort_unless($invitation->isPending(), 410);

            $invitation->update(['revoked_at' => now()]);
        }, attempts: 3);

        unset($this->pendingInvitations);
    }

    public function unshare(int $userId, AccountMutationGate $accountMutationGate): void
    {
        $this->authorize('share', $this->project);
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        DB::transaction(function () use ($userId, $accountMutationGate, $user): void {
            $project = $accountMutationGate->lockProjectManagerOrFail(
                $this->project->account_id,
                $this->project->id,
                $user->id,
            );
            $project->sharedUsers()->detach($userId);
        }, attempts: 3);

        unset($this->sharedUsers);
    }

    #[Computed]
    public function canEdit(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('update', $this->project);
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

    /** @return Collection<int, ProjectInvitation> */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->project->invitations()
            ->pending()
            ->latest()
            ->get();
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
