<?php

namespace App\Jobs;

use App\Ai\Images\ImageModelCatalog;
use App\Enums\AiModelPurpose;
use App\Enums\PhotoCostSource;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Exceptions\PaidAiUnavailable;
use App\Models\PhotoGenerationBatch;
use App\Models\User;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AiOperationResultMetadata;
use App\Services\Ai\AuditedAiExecutor;
use App\Services\Ai\ByokOpenRouterProviderFactory;
use App\Services\LifecycleRuntime;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoGenerationSlotService;
use App\Services\PhotoStorageCleanupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use RuntimeException;
use Throwable;

#[WithoutRelations]
class GeneratePhotoWithModel implements ShouldQueue
{
    use Queueable;

    public int $timeout = 330;

    public int $tries = 0;

    public function __construct(public int $slotId) {}

    public function handle(
        ImageModelCatalog $catalog,
        PhotoGenerationLifecycle $lifecycle,
        ?PhotoGenerationSlotService $slotService = null,
        ?LifecycleRuntime $runtime = null,
        ?AuditedAiExecutor $aiExecutor = null,
        ?ByokOpenRouterProviderFactory $byokProviders = null,
        ?PhotoStorageCleanupService $storageCleanup = null,
    ): void {
        $slotService ??= app(PhotoGenerationSlotService::class);
        $runtime ??= app(LifecycleRuntime::class);
        $aiExecutor ??= app(AuditedAiExecutor::class);
        $byokProviders ??= app(ByokOpenRouterProviderFactory::class);
        $storageCleanup ??= app(PhotoStorageCleanupService::class);
        $runtime->assertReady();
        $claim = $slotService->claim($this->slotId);

        if ($claim === null) {
            return;
        }

        $slot = $claim->slot;
        $batch = PhotoGenerationBatch::query()->find($slot->photo_generation_batch_id);

        if ($batch === null) {
            $slotService->markPreProviderFailed($claim, 'batch_missing');

            return;
        }

        $lease = $lifecycle->acquireForBatch($batch, 'image-generation:'.$slot->provider.':'.$slot->model);

        if ($lease === null) {
            $slotService->markPreProviderFailed($claim, 'authorization_unavailable');

            return;
        }

        try {
            $prompt = $batch->generationPrompt();

            if ($prompt === null) {
                $slotService->markPreProviderFailed($claim, 'prompt_missing');

                return;
            }

            try {
                $candidate = null;

                try {
                    $candidate = $catalog->find($slot->provider, $slot->model);
                } catch (Throwable $exception) {
                    if (! $slot->uses_byok) {
                        throw $exception;
                    }
                }

                $inputs = $lifecycle->validatedBatchInputPhotos($batch);
                $maxReferenceImages = $candidate?->maxReferenceImages()
                    ?? (int) config('account-ai.custom_image_max_reference_images', 4);
                $attachments = $inputs
                    ->take(max(1, $maxReferenceImages))
                    ->map(fn ($photo) => ImageFile::fromStorage($photo->llmInputPath(), $photo->disk))
                    ->all();
                $estimatedCostUsd = $candidate?->effectiveUsdPerImage(count($attachments));
            } catch (Throwable $exception) {
                Log::warning('Generation slot preparation failed before provider execution.', [
                    'slot_id' => $slot->id,
                    'exception_class' => $exception::class,
                ]);
                $slotService->markPreProviderFailed($claim, 'pre_provider_preparation_failed');

                return;
            }

            if (! $claim->resumeStaged) {
                $providerStarted = false;

                try {
                    $user = User::query()->findOrFail($batch->user_id);
                    $response = $aiExecutor->execute(
                        $user,
                        AiModelPurpose::Image,
                        $slot->provider,
                        $slot->model,
                        $prompt,
                        function (AiExecutionContext $context) use ($prompt, $attachments, $byokProviders, $lifecycle, $lease, $slotService, $claim, &$providerStarted) {
                            if (! $lifecycle->leaseIsUsable($lease) || ! $slotService->markProviderStarted($claim)) {
                                throw new RuntimeException('The provider start fence was rejected.');
                            }

                            $providerStarted = true;

                            if (! $context->usesByok()) {
                                return Image::of($prompt)->attachments($attachments)->timeout(300)->generate($context->provider, $context->model);
                            }

                            return $byokProviders->make((string) $context->credential?->secret)->image(
                                $prompt,
                                attachments: $attachments,
                                model: $context->model,
                                timeout: 300,
                            );
                        },
                        hostedEstimatedCostUsd: $estimatedCostUsd,
                        resultMetadata: fn ($response): AiOperationResultMetadata => AiOperationResultMetadata::fromResponse($response),
                        requestedByokModel: $slot->uses_byok ? $slot->model : null,
                        expectByok: $slot->uses_byok,
                        account: $batch->account_id,
                    );
                    $resultMetadata = AiOperationResultMetadata::fromResponse($response);
                    $actualProvider = $resultMetadata->provider ?? ($slot->uses_byok ? 'openrouter' : $slot->provider);
                    $actualModel = $resultMetadata->model ?? $slot->model;
                    $actualCostUsd = $resultMetadata->costUsd ?? $estimatedCostUsd;
                    $actualCostSource = $resultMetadata->costUsd !== null
                        ? PhotoCostSource::Provider
                        : PhotoCostSource::Estimate;
                    $generated = $response->firstImage();
                    $extension = match ($generated->mime()) {
                        'image/jpeg' => 'jpg',
                        'image/webp' => 'webp',
                        default => 'png',
                    };
                    $disk = (string) config('photostudio.disk');
                    $path = $generated->storeAs(rtrim($slot->staging_prefix, '/'), 'original.'.$extension, disk: $disk);

                    if (! is_string($path)) {
                        throw new RuntimeException('Generated image storage failed.');
                    }
                } catch (Throwable $exception) {
                    if (! $providerStarted) {
                        if ($exception instanceof PaidAiUnavailable && $exception->isConcurrencyExceeded()) {
                            if ($slotService->releaseClaim($claim)) {
                                $this->release(max(1, (int) config('account-ai.concurrency_retry_seconds', 5)));

                                return;
                            }

                            $slotService->markPreProviderFailed($claim, 'pre_provider_release_failed');

                            return;
                        }

                        $slotService->markPreProviderFailed($claim, 'pre_provider_ai_unavailable');

                        return;
                    }

                    Log::warning('Generation provider outcome is ambiguous and will not be retried automatically.', [
                        'slot_id' => $slot->id,
                        'exception_class' => $exception::class,
                    ]);
                    $slotService->markAmbiguous($claim, 'provider_or_storage_outcome_ambiguous');

                    return;
                }

                try {
                    if (! $slotService->recordStaged($claim, $disk, $path, $actualProvider, $actualModel, $actualCostUsd, $actualCostSource)) {
                        $workspaceErasureStarted = $claim->slot->newQuery()
                            ->whereKey($slot->id)
                            ->where('failure_code', 'workspace_erasure_started')
                            ->exists();

                        if ($workspaceErasureStarted) {
                            $storageCleanup->deleteOrTrack($disk, [$path]);

                            return;
                        }

                        $persisted = $claim->slot->newQuery()
                            ->whereKey($slot->id)
                            ->where('staged_disk', $disk)
                            ->where('staged_path', $path)
                            ->where('actual_provider', $actualProvider)
                            ->where('actual_model', $actualModel)
                            ->exists();

                        if (! $persisted) {
                            $slotService->markAmbiguous($claim, 'staging_commit_ambiguous');

                            return;
                        }
                    }
                } catch (Throwable $exception) {
                    $workspaceErasureStarted = $claim->slot->newQuery()
                        ->whereKey($slot->id)
                        ->where('failure_code', 'workspace_erasure_started')
                        ->exists();

                    if ($workspaceErasureStarted) {
                        $storageCleanup->deleteOrTrack($disk, [$path]);

                        return;
                    }

                    $persisted = $claim->slot->newQuery()
                        ->whereKey($slot->id)
                        ->where('staged_disk', $disk)
                        ->where('staged_path', $path)
                        ->where('actual_provider', $actualProvider)
                        ->where('actual_model', $actualModel)
                        ->exists();

                    if (! $persisted) {
                        $slotService->markAmbiguous($claim, 'staging_commit_ambiguous');

                        return;
                    }
                }

                $claim->slot->setAttribute('staged_disk', $disk);
                $claim->slot->setAttribute('staged_path', $path);
                $claim->slot->setAttribute('actual_provider', $actualProvider);
                $claim->slot->setAttribute('actual_model', $actualModel);
                $claim->slot->setAttribute('actual_cost_usd', $actualCostUsd);
                $claim->slot->setAttribute('actual_cost_source', $actualCostSource);
                $cost = [$actualCostUsd, $actualCostSource];
            } else {
                $disk = $slot->staged_disk;
                $path = $slot->staged_path;
                $actualProvider = $slot->actual_provider ?? $slot->provider;
                $actualModel = $slot->actual_model ?? $slot->model;
                $cost = [$slot->actual_cost_usd ?? $estimatedCostUsd, $slot->actual_cost_source ?? PhotoCostSource::Estimate];
            }

            if (! is_string($disk) || ! is_string($path) || ! $lifecycle->leaseIsUsable($lease)) {
                $slotService->markAmbiguous($claim, 'staged_output_requires_manual_review');

                return;
            }

            $attributes = [
                'account_id' => $batch->account_id,
                'project_id' => $batch->project_id,
                'user_id' => $batch->user_id,
                'photo_generation_batch_id' => $batch->id,
                'kind' => PhotoKind::Generated,
                'disk' => $disk,
                'path' => $path,
                'processing_status' => PhotoProcessingStatus::Pending,
                'text' => $prompt,
                'text_source' => PhotoTextSource::Auto,
                'provider' => $actualProvider,
                'model' => $actualModel,
                'mode' => PhotoMode::from($slot->mode),
                'cost_usd' => $cost[0],
                'cost_source' => $cost[1],
            ];

            try {
                $photo = $slotService->completeStaged($claim, $attributes);

                if ($photo !== null) {
                    return;
                }
            } catch (Throwable $exception) {
                Log::warning('Generation photo commit will be reconciled from staged state.', [
                    'slot_id' => $slot->id,
                    'exception_class' => $exception::class,
                ]);
            }

            $committed = $claim->slot->newQuery()
                ->whereKey($slot->id)
                ->where('status', PhotoGenerationSlotStatus::Completed)
                ->whereHas('photo', fn ($query) => $query->where('disk', $disk)->where('path', $path))
                ->exists();

            if (! $committed) {
                $staged = $claim->slot->newQuery()
                    ->whereKey($slot->id)
                    ->where('status', PhotoGenerationSlotStatus::Staged)
                    ->where('staged_disk', $disk)
                    ->where('staged_path', $path)
                    ->exists();

                if ($staged) {
                    throw new RuntimeException('Staged generation output requires a queue retry for database finalization.');
                }

                $slotService->markAmbiguous($claim, 'photo_commit_ambiguous');
            }
        } finally {
            $lifecycle->finish($lease);
        }
    }
}
