<?php

namespace App\Jobs;

use App\Ai\Agents\PhotoDescriber;
use App\Enums\AiModelPurpose;
use App\Enums\PhotoTextSource;
use App\Models\Account;
use App\Models\Photo;
use App\Models\User;
use App\Services\Ai\AiExecutionContext;
use App\Services\Ai\AiOperationResultMetadata;
use App\Services\Ai\AuditedAiExecutor;
use App\Services\Ai\ByokOpenRouterProviderFactory;
use App\Services\LifecycleRuntime;
use App\Services\PhotoGenerationLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
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
class DescribeUploadedPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function __construct(public Photo $photo) {}

    public function handle(
        PhotoGenerationLifecycle $lifecycle,
        ?LifecycleRuntime $runtime = null,
        ?AuditedAiExecutor $aiExecutor = null,
        ?ByokOpenRouterProviderFactory $byokProviders = null,
    ): void {
        $runtime ??= app(LifecycleRuntime::class);
        $aiExecutor ??= app(AuditedAiExecutor::class);
        $byokProviders ??= app(ByokOpenRouterProviderFactory::class);
        $runtime->assertReady();
        $photo = Photo::query()->find($this->photo->id);

        if ($photo === null || filled($photo->text) || $photo->text_source !== null) {
            return;
        }

        /** @var array{provider: string, model: string, timeout?: int} $analysis */
        $analysis = config('photostudio.analysis');
        $prompt = 'Describe this photo.';
        $user = User::query()->findOrFail($photo->user_id);
        $lease = $lifecycle->acquireForPhoto($photo, 'auto-description');

        if ($lease === null) {
            $claim = $this->claim($photo);

            if ($claim !== null) {
                [$token, $fence] = $claim;
                $this->recordLifecycleDenial($photo, $user, $aiExecutor, $analysis, $prompt, $token, $fence);
            }

            return;
        }

        try {
            $claim = $this->claim($photo);

            if ($claim === null) {
                return;
            }

            [$token, $fence] = $claim;
            $providerStarted = false;

            if (! $lifecycle->leaseIsUsable($lease)) {
                $this->recordLifecycleDenial($photo, $user, $aiExecutor, $analysis, $prompt, $token, $fence);

                return;
            }

            try {
                $attachments = [Image::fromStorage($photo->llmInputPath(), $photo->disk)];
                $agent = new PhotoDescriber;
                $response = $aiExecutor->execute(
                    $user,
                    AiModelPurpose::ShortText,
                    $analysis['provider'],
                    $analysis['model'],
                    $prompt,
                    function (AiExecutionContext $context) use ($agent, $prompt, $attachments, $analysis, $byokProviders, $lifecycle, $lease, $photo, $token, $fence, &$providerStarted): AgentResponse {
                        if (! $lifecycle->leaseIsUsable($lease) || ! $this->markProviderStarted($photo->id, $token, $fence)) {
                            throw new RuntimeException('Photo description authorization changed before the provider call.');
                        }

                        $providerStarted = true;

                        if (! $context->usesByok()) {
                            return $agent->prompt($prompt, attachments: $attachments, provider: $context->provider, model: $context->model, timeout: $analysis['timeout'] ?? 120);
                        }

                        $provider = $byokProviders->make((string) $context->credential?->secret);

                        return $provider->prompt(new AgentPrompt($agent, $prompt, $attachments, $provider, $context->model, $analysis['timeout'] ?? 120));
                    },
                    fn (AgentResponse $response): string => $response->text,
                    resultMetadata: fn (AgentResponse $response): AiOperationResultMetadata => AiOperationResultMetadata::fromResponse($response),
                    account: $photo->account_id,
                );
            } catch (Throwable $exception) {
                Log::warning('Photo description could not be completed and will not be retried automatically.', [
                    'photo_id' => $photo->id,
                    'exception_class' => $exception::class,
                    'provider_started' => $providerStarted,
                ]);

                if ($providerStarted) {
                    $this->markAmbiguous($photo->id, $token, $fence, 'provider_outcome_ambiguous');
                } else {
                    $this->markPreProviderFailed($photo->id, $token, $fence);
                }

                return;
            }

            if (! $response instanceof StructuredAgentResponse || blank($response['description'] ?? null)) {
                $this->markAmbiguous($photo->id, $token, $fence, 'provider_response_unusable');

                return;
            }

            if (! $lifecycle->leaseIsUsable($lease)) {
                $this->markAmbiguous($photo->id, $token, $fence, 'authorization_changed_after_provider');

                return;
            }

            try {
                $committed = $lifecycle->withUsableLease($lease, function () use ($photo, $response, $token, $fence): bool {
                    $locked = Photo::query()->lockForUpdate()->find($photo->id);

                    if ($locked === null
                        || filled($locked->text)
                        || $locked->text_source !== null
                        || $locked->description_state !== 'provider_started'
                        || $locked->description_execution_token !== $token
                        || $locked->description_fence !== $fence
                    ) {
                        return false;
                    }

                    return $locked->update([
                        'text' => (string) $response['description'],
                        'text_source' => PhotoTextSource::Auto,
                        'description_state' => 'completed',
                        'description_claim_expires_at' => null,
                        'description_failure_code' => null,
                    ]);
                });

                if ($committed !== true) {
                    $this->markAmbiguous($photo->id, $token, $fence, 'authorization_changed_before_commit');
                }
            } catch (Throwable $exception) {
                $committed = Photo::query()
                    ->whereKey($photo->id)
                    ->where('description_state', 'completed')
                    ->where('text_source', PhotoTextSource::Auto)
                    ->where('text', (string) $response['description'])
                    ->exists();

                if (! $committed) {
                    $this->markAmbiguous($photo->id, $token, $fence, 'description_commit_ambiguous');
                }
            }
        } finally {
            $lifecycle->finish($lease);
        }
    }

    /** @return array{string, int}|null */
    private function claim(Photo $photo): ?array
    {
        return DB::transaction(function () use ($photo): ?array {
            $account = Account::query()->lockForUpdate()->find($photo->account_id);
            $locked = Photo::query()->lockForUpdate()->find($photo->id);

            if ($locked === null || in_array($locked->description_state, ['completed', 'failed', 'ambiguous'], true)) {
                return null;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $locked->update([
                    'description_state' => 'failed',
                    'description_failure_code' => 'workspace_erasure_started',
                    'description_claim_expires_at' => null,
                ]);

                return null;
            }

            if ($locked->description_state === 'provider_started') {
                $locked->update([
                    'description_state' => 'ambiguous',
                    'description_failure_code' => 'provider_started_worker_lost',
                    'description_claim_expires_at' => null,
                ]);

                return null;
            }

            if ($locked->description_state === 'claimed'
                && $locked->description_claim_expires_at !== null
                && $locked->description_claim_expires_at->isFuture()
            ) {
                return null;
            }

            $token = (string) Str::uuid7();
            $fence = $locked->description_fence + 1;
            $locked->update([
                'description_state' => 'claimed',
                'description_execution_token' => $token,
                'description_fence' => $fence,
                'description_claim_expires_at' => now()->addSeconds(max(60, (int) config('photostudio.lifecycle.claim_seconds', 600))),
            ]);

            return [$token, $fence];
        }, attempts: 3);
    }

    private function markAmbiguous(int $photoId, string $token, int $fence, string $failureCode): void
    {
        DB::transaction(function () use ($photoId, $token, $fence, $failureCode): void {
            $this->lockAccountForPhoto($photoId);
            Photo::query()
                ->whereKey($photoId)
                ->where('description_execution_token', $token)
                ->where('description_fence', $fence)
                ->where('description_state', 'provider_started')
                ->update([
                    'description_state' => 'ambiguous',
                    'description_failure_code' => Str::limit($failureCode, 80, ''),
                    'description_claim_expires_at' => null,
                ]);
        }, attempts: 3);
    }

    private function markProviderStarted(int $photoId, string $token, int $fence): bool
    {
        return DB::transaction(function () use ($photoId, $token, $fence): bool {
            $account = $this->lockAccountForPhoto($photoId);
            $photo = Photo::query()->lockForUpdate()->find($photoId);

            if ($photo === null
                || $photo->description_state !== 'claimed'
                || $photo->description_execution_token !== $token
                || $photo->description_fence !== $fence
            ) {
                return false;
            }

            if ($account === null || $account->erasure_started_at !== null) {
                $photo->update([
                    'description_state' => 'failed',
                    'description_failure_code' => 'workspace_erasure_started',
                    'description_claim_expires_at' => null,
                ]);

                return false;
            }

            return $photo->update([
                'description_state' => 'provider_started',
                'description_provider_started_at' => now(),
            ]);
        }, attempts: 3);
    }

    private function markPreProviderFailed(int $photoId, string $token, int $fence): bool
    {
        return DB::transaction(function () use ($photoId, $token, $fence): bool {
            $this->lockAccountForPhoto($photoId);

            return Photo::query()
                ->whereKey($photoId)
                ->where('description_execution_token', $token)
                ->where('description_fence', $fence)
                ->where('description_state', 'claimed')
                ->update([
                    'description_state' => 'failed',
                    'description_failure_code' => 'pre_provider_failure',
                    'description_claim_expires_at' => null,
                ]) === 1;
        }, attempts: 3);
    }

    /** @param array{provider: string, model: string} $analysis */
    private function recordLifecycleDenial(
        Photo $photo,
        User $user,
        AuditedAiExecutor $aiExecutor,
        array $analysis,
        string $prompt,
        string $token,
        int $fence,
    ): void {
        DB::transaction(function () use ($photo, $user, $aiExecutor, $analysis, $prompt, $token, $fence): void {
            if (! $this->markPreProviderFailed($photo->id, $token, $fence)) {
                return;
            }

            $aiExecutor->recordPreProviderDenial(
                $user,
                AiModelPurpose::ShortText,
                $analysis['provider'],
                $analysis['model'],
                $prompt,
                $photo->account_id,
            );
        }, attempts: 3);
    }

    private function lockAccountForPhoto(int $photoId): ?Account
    {
        $accountId = Photo::query()->whereKey($photoId)->value('account_id');

        return is_numeric($accountId)
            ? Account::query()->lockForUpdate()->find((int) $accountId)
            : null;
    }
}
