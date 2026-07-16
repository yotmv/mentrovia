<?php

namespace App\Jobs;

use App\Enums\PhotoProcessingStatus;
use App\Images\PhotoDerivativeResult;
use App\Images\PhotoDerivativeService;
use App\Models\Photo;
use App\Services\LifecycleRuntime;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoStorageCleanupService;
use App\Services\PhotoWorkReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[DeleteWhenMissingModels, WithoutRelations]
class GeneratePhotoDerivatives implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public Photo $photo) {}

    public function handle(
        PhotoDerivativeService $service,
        PhotoGenerationLifecycle $lifecycle,
        ?PhotoStorageCleanupService $cleanupService = null,
        ?PhotoWorkReconciler $workReconciler = null,
        ?LifecycleRuntime $runtime = null,
    ): void {
        $runtime ??= app(LifecycleRuntime::class);
        $runtime->assertReady();
        $cleanupService ??= app(PhotoStorageCleanupService::class);
        $workReconciler ??= app(PhotoWorkReconciler::class);
        $lease = $lifecycle->acquireForPhoto($this->photo, 'photo-derivatives');

        if ($lease === null) {
            return;
        }

        $result = null;
        $resultCommitted = false;

        try {
            $photo = $lifecycle->withUsableLease(
                $lease,
                function (): ?Photo {
                    $photo = Photo::query()->find($this->photo->id);

                    if ($photo === null) {
                        return null;
                    }

                    $photo->update([
                        'processing_status' => PhotoProcessingStatus::Processing,
                        'processing_error' => null,
                    ]);

                    return $photo;
                },
            );

            if (! $photo instanceof Photo) {
                return;
            }

            $result = $service->process($photo);
            $oldPaths = [];

            $committedPhoto = $lifecycle->withUsableLease(
                $lease,
                function () use ($result, $cleanupService, $workReconciler, &$oldPaths): ?Photo {
                    $photo = Photo::query()->lockForUpdate()->find($this->photo->id);

                    if ($photo === null) {
                        return null;
                    }

                    $oldPaths = collect($photo->derivatives ?? [])->pluck('path')->filter()->values()->all();

                    foreach ($oldPaths as $oldPath) {
                        $cleanupService->track($photo->disk, $oldPath);
                    }

                    $photo->update([
                        'derivatives' => $result->derivatives,
                        'width' => $result->width,
                        'height' => $result->height,
                        'size_bytes' => $result->sizeBytes,
                        'processing_status' => PhotoProcessingStatus::Ready,
                        'processing_error' => null,
                        'processed_at' => now(),
                    ]);

                    if ($photo->isUploaded() && blank($photo->text) && $photo->text_source === null) {
                        $workReconciler->scheduleDescription($photo);
                    }

                    return $photo;
                },
            );

            if (! $committedPhoto instanceof Photo) {
                $cleanupService->deleteOrTrack($photo->disk, $result->storedPaths);

                return;
            }

            $resultCommitted = true;

            try {
                $cleanupService->deleteOrTrack($committedPhoto->disk, $oldPaths);
            } catch (Throwable $cleanupException) {
                Log::critical('Old derivative cleanup will continue from its durable outbox.', [
                    'photo_id' => $committedPhoto->id,
                    'exception_class' => $cleanupException::class,
                ]);
            }

        } catch (Throwable $exception) {
            if ($result instanceof PhotoDerivativeResult && ! $resultCommitted) {
                $resultCommitted = Photo::query()
                    ->whereKey($this->photo->id)
                    ->where('processing_status', PhotoProcessingStatus::Ready)
                    ->get(['derivatives'])
                    ->contains(function (Photo $photo) use ($result): bool {
                        $currentPaths = collect($photo->derivatives ?? [])->pluck('path')->sort()->values()->all();
                        $resultPaths = collect($result->derivatives)->pluck('path')->sort()->values()->all();

                        return $currentPaths === $resultPaths;
                    });

                if (! $resultCommitted) {
                    $cleanupService->deleteOrTrack($this->photo->disk, $result->storedPaths);
                }
            }

            if (! $resultCommitted) {
                $lifecycle->withUsableLease($lease, function (): void {
                    Photo::query()->whereKey($this->photo->id)->update([
                        'processing_status' => PhotoProcessingStatus::Failed,
                        'processing_error' => 'Photo processing failed. Retrying automatically.',
                    ]);
                });
            }

            Log::warning('Photo derivative job failed.', [
                'photo_id' => $this->photo->id,
                'exception_class' => $exception::class,
            ]);

            throw new RuntimeException('Photo derivative generation failed.');
        } finally {
            $lifecycle->finish($lease);
        }
    }
}
