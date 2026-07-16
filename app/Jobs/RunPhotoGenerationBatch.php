<?php

namespace App\Jobs;

use App\Ai\Agents\PhotoBatchAnalyst;
use App\Ai\Images\ImageModelCandidate;
use App\Ai\Images\ImageModelChooser;
use App\Ai\Images\ImageRequirements;
use App\Enums\AiModelPurpose;
use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoMode;
use App\Models\Account;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoOperationLease;
use App\Models\User;
use App\Services\Ai\AiAccountGate;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AiOperationResultMetadata;
use App\Services\Ai\AuditedAiExecutor;
use App\Services\Ai\ByokOpenRouterProviderFactory;
use App\Services\LifecycleRuntime;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoGenerationSlotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

#[DeleteWhenMissingModels, WithoutRelations]
class RunPhotoGenerationBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 300;

    public function __construct(public PhotoGenerationBatch $generationBatch) {}

    public function handle(
        ImageModelChooser $chooser,
        PhotoGenerationLifecycle $lifecycle,
        ?PhotoGenerationSlotService $slotService = null,
        ?LifecycleRuntime $runtime = null,
        ?AuditedAiExecutor $aiExecutor = null,
        ?AiAccountGate $aiGate = null,
        ?ByokOpenRouterProviderFactory $byokProviders = null,
    ): void {
        $slotService ??= app(PhotoGenerationSlotService::class);
        $runtime ??= app(LifecycleRuntime::class);
        $aiExecutor ??= app(AuditedAiExecutor::class);
        $aiGate ??= app(AiAccountGate::class);
        $byokProviders ??= app(ByokOpenRouterProviderFactory::class);
        $runtime->assertReady();
        $batch = PhotoGenerationBatch::query()->find($this->generationBatch->id);

        if ($batch === null) {
            return;
        }

        /** @var array{provider: string, model: string, timeout?: int} $analysisConfig */
        $analysisConfig = config('photostudio.analysis');
        $analysisPrompt = $this->analysisPrompt($batch, count($batch->input_photo_ids));
        $user = User::query()->findOrFail($batch->user_id);

        try {
            $lease = $lifecycle->acquireForBatch($batch, 'batch-analysis-and-model-selection');
        } catch (Throwable $exception) {
            DB::transaction(function () use ($batch): void {
                $account = $this->lockAccountForBatch($batch->id);
                $lockedBatch = PhotoGenerationBatch::query()->lockForUpdate()->find($batch->id);

                if ($lockedBatch === null) {
                    return;
                }

                $workspaceErasureStarted = $account === null || $account->erasure_started_at !== null;
                $lockedBatch->update([
                    'status' => GenerationBatchStatus::Failed,
                    'analysis_state' => 'failed',
                    'analysis_failure_code' => $workspaceErasureStarted ? 'workspace_erasure_started' : 'invalid_batch_inputs',
                    'error' => $workspaceErasureStarted
                        ? 'Photo analysis was stopped because the workspace is being deleted.'
                        : 'Photo generation inputs are invalid.',
                ]);
            }, attempts: 3);

            Log::warning('Photo generation batch input validation failed.', [
                'batch_id' => $batch->id,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        if ($lease === null) {
            $claim = $this->claim($batch);

            if ($claim !== null) {
                [$executionToken, $fence] = $claim;
                $this->recordLifecycleDenial($batch, $user, $aiExecutor, $analysisConfig, $analysisPrompt, $executionToken, $fence);
            }

            return;
        }

        $executionToken = null;
        $fence = null;
        $providerStarted = false;

        try {
            $claim = $this->claim($batch);

            if ($claim === null) {
                return;
            }

            [$executionToken, $fence] = $claim;
            $inputs = $lifecycle->validatedBatchInputPhotos($batch);

            if (! $lifecycle->leaseIsUsable($lease)) {
                $this->recordLifecycleDenial(
                    $batch,
                    $user,
                    $aiExecutor,
                    $analysisConfig,
                    $this->analysisPrompt($batch, $inputs->count()),
                    $executionToken,
                    $fence,
                );

                return;
            }

            $analysis = $this->analyze(
                $batch,
                $inputs,
                $aiExecutor,
                $byokProviders,
                $lifecycle,
                $lease,
                $executionToken,
                $fence,
                $providerStarted,
            );

            if (! $lifecycle->leaseIsUsable($lease)) {
                $this->markAnalysisAmbiguous($batch->id, $executionToken, $fence);

                return;
            }

            $prompt = filled($analysis['group_prompt'] ?? null)
                ? (string) $analysis['group_prompt']
                : (string) $batch->user_text;

            if (blank($prompt)) {
                throw new RuntimeException('Photo analysis did not produce a usable prompt.');
            }

            $mode = collect((array) ($analysis['images'] ?? []))->pluck('verdict')->contains(PhotoMode::Recreate->value)
                ? PhotoMode::Recreate
                : PhotoMode::Cleanup;
            $requirements = new ImageRequirements(
                requiresImageInput: true,
                requiresEditing: $mode === PhotoMode::Cleanup,
                minQuality: (int) config('photostudio.chooser.requirements.min_quality'),
                maxUsdPerImage: (float) config('photostudio.chooser.requirements.max_usd_per_image'),
                referenceImageCount: $inputs->count(),
            );
            $resultCount = (int) config('photostudio.results_per_batch', 3);
            $byokModels = $aiGate->activeByokModels($user, AiModelPurpose::Image, $batch->account_id);

            if ($byokModels !== null) {
                $models = collect($byokModels)->unique()->take($resultCount)
                    ->map(fn (string $model): array => [
                        'provider' => 'openrouter',
                        'model' => $model,
                        'uses_byok' => true,
                    ])->values()->all();
                $selectedDigests = collect($models)->map(fn (array $route): array => [
                    'choice_id' => $route['provider'].'::'.$route['model'],
                    'account_routing' => 'byok',
                ])->all();
            } else {
                $selected = $chooser->forConfiguredProvider($requirements, $resultCount, $user, $batch->account_id);
                $models = $selected->map(fn (ImageModelCandidate $candidate): array => [
                    'provider' => $candidate->provider,
                    'model' => $candidate->model,
                    'uses_byok' => false,
                ])->values()->all();
                $selectedDigests = $selected->map(fn (ImageModelCandidate $candidate): array => $candidate->toDigest())->all();
            }

            $committed = $lifecycle->withUsableLease($lease, function () use ($batch, $executionToken, $fence, $analysis, $selectedDigests, $models, $mode, $slotService): bool {
                $locked = PhotoGenerationBatch::query()->lockForUpdate()->find($batch->id);

                if ($locked === null
                    || $locked->analysis_state !== 'provider_started'
                    || $locked->analysis_execution_token !== $executionToken
                    || $locked->analysis_fence !== $fence
                ) {
                    throw new RuntimeException('The batch analysis fence is no longer current.');
                }

                $locked->update([
                    'status' => GenerationBatchStatus::Processing,
                    'analysis_state' => 'completed',
                    'analysis' => $analysis,
                    'selected_models' => $selectedDigests,
                    'analysis_claim_expires_at' => null,
                    'analysis_failure_code' => null,
                ]);
                $slotService->createAndEnqueue($locked, $models, $mode);

                return true;
            });

            if ($committed !== true) {
                $this->markAnalysisAmbiguous($batch->id, $executionToken, $fence);
            }
        } catch (Throwable $exception) {
            Log::warning('Photo generation analysis requires no automatic provider retry.', [
                'batch_id' => $batch->id,
                'exception_class' => $exception::class,
            ]);

            if ($providerStarted && is_string($executionToken) && is_int($fence)) {
                $committed = PhotoGenerationBatch::query()
                    ->whereKey($batch->id)
                    ->where('analysis_state', 'completed')
                    ->whereHas('generationSlots')
                    ->exists();

                if (! $committed) {
                    $this->markAnalysisAmbiguous($batch->id, $executionToken, $fence);
                }
            } elseif (is_string($executionToken) && is_int($fence)) {
                $this->markPreProviderFailed($batch->id, $executionToken, $fence);
            }
        } finally {
            $lifecycle->finish($lease);
        }
    }

    /** @return array{string, int}|null */
    private function claim(PhotoGenerationBatch $batch): ?array
    {
        return DB::transaction(function () use ($batch): ?array {
            $account = Account::query()->lockForUpdate()->find($batch->account_id);
            $locked = PhotoGenerationBatch::query()->lockForUpdate()->find($batch->id);

            if ($locked === null || in_array($locked->analysis_state, ['completed', 'failed', 'ambiguous'], true)) {
                return null;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $locked->update([
                    'status' => GenerationBatchStatus::Failed,
                    'analysis_state' => 'failed',
                    'analysis_failure_code' => 'workspace_erasure_started',
                    'analysis_claim_expires_at' => null,
                    'error' => 'Photo analysis was stopped because the workspace is being deleted.',
                ]);

                return null;
            }

            if ($locked->analysis_state === 'provider_started') {
                $locked->update([
                    'status' => GenerationBatchStatus::Failed,
                    'analysis_state' => 'ambiguous',
                    'analysis_failure_code' => 'provider_started_worker_lost',
                    'error' => 'Photo analysis requires manual review.',
                    'analysis_claim_expires_at' => null,
                ]);

                return null;
            }

            if ($locked->analysis_state === 'claimed'
                && $locked->analysis_claim_expires_at !== null
                && $locked->analysis_claim_expires_at->isFuture()
            ) {
                return null;
            }

            $token = (string) Str::uuid7();
            $fence = $locked->analysis_fence + 1;
            $locked->update([
                'status' => GenerationBatchStatus::Processing,
                'analysis_state' => 'claimed',
                'analysis_execution_token' => $token,
                'analysis_fence' => $fence,
                'analysis_claim_expires_at' => now()->addSeconds(max(60, (int) config('photostudio.lifecycle.claim_seconds', 600))),
            ]);

            return [$token, $fence];
        }, attempts: 3);
    }

    protected function markProviderStarted(int $batchId, string $token, int $fence): bool
    {
        return DB::transaction(function () use ($batchId, $token, $fence): bool {
            $account = $this->lockAccountForBatch($batchId);
            $batch = PhotoGenerationBatch::query()->lockForUpdate()->find($batchId);

            if ($batch === null
                || $batch->analysis_state !== 'claimed'
                || $batch->analysis_execution_token !== $token
                || $batch->analysis_fence !== $fence
            ) {
                return false;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $batch->update([
                    'status' => GenerationBatchStatus::Failed,
                    'analysis_state' => 'failed',
                    'analysis_failure_code' => 'workspace_erasure_started',
                    'analysis_claim_expires_at' => null,
                    'error' => 'Photo analysis was stopped because the workspace is being deleted.',
                ]);

                return false;
            }

            return $batch->update([
                'analysis_state' => 'provider_started',
                'analysis_provider_started_at' => now(),
            ]);
        }, attempts: 3);
    }

    private function markAnalysisAmbiguous(int $batchId, string $token, int $fence): void
    {
        DB::transaction(function () use ($batchId, $token, $fence): void {
            $this->lockAccountForBatch($batchId);
            PhotoGenerationBatch::query()
                ->whereKey($batchId)
                ->where('analysis_execution_token', $token)
                ->where('analysis_fence', $fence)
                ->where('analysis_state', 'provider_started')
                ->update([
                    'status' => GenerationBatchStatus::Failed,
                    'analysis_state' => 'ambiguous',
                    'analysis_failure_code' => 'provider_outcome_or_commit_ambiguous',
                    'analysis_claim_expires_at' => null,
                    'error' => 'Photo analysis requires manual review.',
                ]);
        }, attempts: 3);
    }

    private function markPreProviderFailed(int $batchId, string $token, int $fence): bool
    {
        return DB::transaction(function () use ($batchId, $token, $fence): bool {
            $this->lockAccountForBatch($batchId);

            return PhotoGenerationBatch::query()
                ->whereKey($batchId)
                ->where('analysis_execution_token', $token)
                ->where('analysis_fence', $fence)
                ->where('analysis_state', 'claimed')
                ->update([
                    'status' => GenerationBatchStatus::Failed,
                    'analysis_state' => 'failed',
                    'analysis_failure_code' => 'pre_provider_failure',
                    'analysis_claim_expires_at' => null,
                    'error' => 'Photo analysis could not start.',
                ]) === 1;
        }, attempts: 3);
    }

    /**
     * @param  EloquentCollection<int, Photo>  $inputs
     * @return array<string, mixed>
     */
    protected function analyze(
        PhotoGenerationBatch $batch,
        EloquentCollection $inputs,
        AuditedAiExecutor $aiExecutor,
        ByokOpenRouterProviderFactory $byokProviders,
        PhotoGenerationLifecycle $lifecycle,
        PhotoOperationLease $lease,
        string $executionToken,
        int $fence,
        bool &$providerStarted,
    ): array {
        $config = config('photostudio.analysis');
        $prompt = $this->analysisPrompt($batch, $inputs->count());
        $attachments = $inputs->map(
            fn (Photo $photo) => Image::fromStorage($photo->llmInputPath(), $photo->disk)
        )->all();
        $agent = new PhotoBatchAnalyst;
        $user = User::query()->findOrFail($batch->user_id);
        $response = $aiExecutor->execute(
            $user,
            AiModelPurpose::ShortText,
            $config['provider'],
            $config['model'],
            $prompt,
            function (AiExecutionContext $context) use ($agent, $prompt, $attachments, $config, $byokProviders, $lifecycle, $lease, $batch, $executionToken, $fence, &$providerStarted): AgentResponse {
                if (! $lifecycle->leaseIsUsable($lease) || ! $this->markProviderStarted($batch->id, $executionToken, $fence)) {
                    throw new RuntimeException('Photo batch authorization changed before the provider call.');
                }

                $providerStarted = true;

                if (! $context->usesByok()) {
                    return $agent->prompt($prompt, attachments: $attachments, provider: $context->provider, model: $context->model, timeout: $config['timeout'] ?? 120);
                }

                $provider = $byokProviders->make((string) $context->credential?->secret);

                return $provider->prompt(new AgentPrompt($agent, $prompt, $attachments, $provider, $context->model, $config['timeout'] ?? 120));
            },
            fn (AgentResponse $response): string => $response->text,
            resultMetadata: fn (AgentResponse $response): AiOperationResultMetadata => AiOperationResultMetadata::fromResponse($response),
            account: $batch->account_id,
        );

        return $response instanceof StructuredAgentResponse ? $response->toArray() : [];
    }

    private function analysisPrompt(PhotoGenerationBatch $batch, int $inputCount): string
    {
        return sprintf(
            "Analyze the %d attached photos.\n\nUser notes: %s",
            $inputCount,
            filled($batch->user_text) ? $batch->user_text : '(none provided)',
        );
    }

    /** @param array{provider: string, model: string} $analysisConfig */
    private function recordLifecycleDenial(
        PhotoGenerationBatch $batch,
        User $user,
        AuditedAiExecutor $aiExecutor,
        array $analysisConfig,
        string $prompt,
        string $token,
        int $fence,
    ): void {
        DB::transaction(function () use ($batch, $user, $aiExecutor, $analysisConfig, $prompt, $token, $fence): void {
            if (! $this->markPreProviderFailed($batch->id, $token, $fence)) {
                return;
            }

            $aiExecutor->recordPreProviderDenial(
                $user,
                AiModelPurpose::ShortText,
                $analysisConfig['provider'],
                $analysisConfig['model'],
                $prompt,
                $batch->account_id,
            );
        }, attempts: 3);
    }

    private function lockAccountForBatch(int $batchId): ?Account
    {
        $accountId = PhotoGenerationBatch::query()->whereKey($batchId)->value('account_id');

        return is_numeric($accountId)
            ? Account::query()->lockForUpdate()->find((int) $accountId)
            : null;
    }
}
