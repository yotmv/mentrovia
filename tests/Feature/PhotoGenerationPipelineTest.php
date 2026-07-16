<?php

namespace Tests\Feature;

use App\Ai\Agents\PhotoBatchAnalyst;
use App\Ai\Images\ImageModelCatalog;
use App\Ai\Images\ImageModelChooser;
use App\Enums\AiAuditEvent;
use App\Enums\AiModelMode;
use App\Enums\AiModelPurpose;
use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoCostSource;
use App\Enums\PhotoGenerationSlotStatus;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\GeneratePhotoWithModel;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\AiAccountSetting;
use App\Models\AiModelPreference;
use App\Models\AiOperationAudit;
use App\Models\AiProviderCredential;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\PhotoGenerationSlot;
use App\Models\PhotoOperationLease;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AuditedAiExecutor;
use App\Services\Ai\ByokHttpFactory;
use App\Services\Ai\ByokOpenRouterProviderFactory;
use App\Services\PhotoGenerationLifecycle;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Events\GeneratingImage;
use Laravel\Ai\Events\ImageGenerated;
use Laravel\Ai\Image;
use Livewire\Livewire;
use Tests\TestCase;

class PhotoGenerationPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        config([
            'photostudio.disk' => 's3',
            'photostudio.provider' => 'auto',
            'photostudio.chooser.llm.enabled' => false,
            'ai.providers.openrouter.key' => 'test-key',
            'ai.providers.replicate.key' => 'test-key',
            'ai.providers.stability.key' => 'test-key',
        ]);
    }

    /**
     * @return array{Project, User, array<int, Photo>}
     */
    protected function projectWithUploads(int $count = 2): array
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        $photos = Photo::factory()
            ->count($count)
            ->for($project)
            ->for($owner, 'user')
            ->create();

        foreach ($photos as $photo) {
            Storage::disk('s3')->put($photo->path, 'fake-image-bytes');
        }

        return [$project, $owner, $photos->all()];
    }

    protected function generationSlotJob(
        PhotoGenerationBatch $batch,
        string $provider,
        string $model,
        PhotoMode $mode,
        bool $usesByok = false,
    ): GeneratePhotoWithModel {
        $slot = PhotoGenerationSlot::factory()->for($batch, 'generationBatch')->create([
            'provider' => $provider,
            'model' => $model,
            'mode' => $mode,
            'uses_byok' => $usesByok,
            'status' => PhotoGenerationSlotStatus::Queued,
            'enqueued_at' => now(),
        ]);

        return new GeneratePhotoWithModel($slot->id);
    }

    public function test_generate_action_creates_a_batch_and_queues_the_pipeline(): void
    {
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('toggleSelection', $photos[0]->id)
            ->call('toggleSelection', $photos[1]->id)
            ->set('generationNotes', 'Polished, showroom lighting')
            ->call('generate')
            ->assertHasNoErrors();

        $batch = $project->generationBatches()->sole();

        $this->assertSame([$photos[0]->id, $photos[1]->id], $batch->input_photo_ids);
        $this->assertSame('Polished, showroom lighting', $batch->user_text);

        Queue::assertPushed(RunPhotoGenerationBatch::class, 1);
    }

    public function test_generate_requires_a_selection(): void
    {
        Queue::fake();

        [$project, $owner] = $this->projectWithUploads();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('generate')
            ->assertHasErrors('selectedPhotoIds');

        Queue::assertNothingPushed();
    }

    public function test_a_batch_dispatch_failure_rolls_back_the_unqueued_batch(): void
    {
        config([
            'queue.connections.lifecycle-database.table' => 'missing_jobs_table',
        ]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('toggleSelection', $photos[0]->id)
            ->call('generate')
            ->assertSet('aiError', __('AI could not start this request. Retry. If the problem continues, contact support.'));

        $this->assertFalse($project->generationBatches()->exists());
    }

    public function test_the_generation_form_discloses_paid_ai_work_before_a_batch_starts(): void
    {
        [$project, $owner] = $this->projectWithUploads();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->assertSee('This starts paid AI work: up to three image generations.')
            ->assertSee('$0.10');
    }

    public function test_the_batch_job_analyzes_selects_three_models_and_fans_out(): void
    {
        Queue::fake();

        PhotoBatchAnalyst::fake([[
            'subject' => 'Granite countertop',
            'intended_final_state' => 'Clean, polished countertop',
            'style_notes' => 'Warm showroom lighting',
            'group_prompt' => 'Produce a clean, polished granite countertop photo.',
            'images' => [
                ['index' => 0, 'verdict' => 'cleanup', 'defects' => ['dust'], 'prompt' => 'Clean image 0', 'text' => 'Dusty slab'],
                ['index' => 1, 'verdict' => 'cleanup', 'defects' => ['clutter'], 'prompt' => 'Clean image 1', 'text' => 'Cluttered slab'],
            ],
        ]]);

        [$project, $owner, $photos] = $this->projectWithUploads();

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'user_text' => 'Make it showroom ready',
            'input_photo_ids' => collect($photos)->pluck('id')->all(),
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

        $batch->refresh();

        $this->assertSame(GenerationBatchStatus::Processing, $batch->status);
        $this->assertSame('Granite countertop', $batch->analysis['subject']);
        $this->assertCount(3, $batch->selected_models);

        $this->assertCount(3, $batch->generationSlots);
        $this->assertTrue($batch->generationSlots->every(fn (PhotoGenerationSlot $slot): bool => $slot->mode === PhotoMode::Cleanup->value));
        Queue::assertPushed(GeneratePhotoWithModel::class, 3);
    }

    public function test_byok_image_models_fan_out_once_each_in_the_configured_order(): void
    {
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);
        AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true]);
        AiProviderCredential::factory()->for($owner)->create(['secret' => 'customer-openrouter-key']);
        AiModelPreference::factory()->for($owner)->create([
            'purpose' => AiModelPurpose::Image,
            'mode' => AiModelMode::Custom,
            'model_ids' => ['vendor/image-a', 'vendor/image-b', 'vendor/image-c'],
        ]);
        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'user_text' => 'Make it showroom ready',
            'input_photo_ids' => [$photos[0]->id],
        ]);
        $job = new class($batch) extends RunPhotoGenerationBatch
        {
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
                $providerStarted = $this->markProviderStarted($batch->id, $executionToken, $fence);

                return [
                    'group_prompt' => 'Produce a showroom-ready result.',
                    'images' => [['verdict' => 'cleanup']],
                ];
            }
        };

        $job->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

        $slots = $batch->generationSlots()->orderBy('id')->get();

        $this->assertSame(['vendor/image-a', 'vendor/image-b', 'vendor/image-c'], $slots->pluck('model')->all());
        $this->assertTrue($slots->every(fn (PhotoGenerationSlot $slot): bool => $slot->uses_byok));
        $this->assertSame(
            ['openrouter::vendor/image-a', 'openrouter::vendor/image-b', 'openrouter::vendor/image-c'],
            collect($batch->fresh()->selected_models)->pluck('choice_id')->all(),
        );
        Queue::assertPushed(GeneratePhotoWithModel::class, 3);
    }

    public function test_a_recreate_verdict_switches_the_batch_mode(): void
    {
        Queue::fake();

        PhotoBatchAnalyst::fake([[
            'subject' => 'Countertop',
            'intended_final_state' => 'Finished install',
            'style_notes' => 'Natural light',
            'group_prompt' => 'Recreate the finished install.',
            'images' => [
                ['index' => 0, 'verdict' => 'recreate', 'defects' => ['half installed'], 'prompt' => 'Recreate', 'text' => 'Partial install'],
            ],
        ]]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'input_photo_ids' => [$photos[0]->id],
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

        $this->assertTrue($batch->generationSlots()->get()->every(
            fn (PhotoGenerationSlot $slot): bool => $slot->mode === PhotoMode::Recreate->value
        ));
    }

    public function test_an_empty_analysis_response_uses_user_notes_without_repeating_the_provider_call(): void
    {
        Queue::fake();

        PhotoBatchAnalyst::fake([[]]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'user_text' => 'Produce a clean, polished granite countertop photo.',
            'input_photo_ids' => [$photos[0]->id],
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

        expect($batch->fresh()->generationPrompt())->toBe('Produce a clean, polished granite countertop photo.');
        PhotoBatchAnalyst::assertPrompted(fn () => true);
        Queue::assertPushed(GeneratePhotoWithModel::class, 3);
    }

    public function test_a_persistently_empty_analysis_falls_back_to_the_user_notes(): void
    {
        Queue::fake();

        PhotoBatchAnalyst::fake([[]]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'user_text' => 'Make it showroom ready',
            'input_photo_ids' => [$photos[0]->id],
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

        expect($batch->fresh()->generationPrompt())->toBe('Make it showroom ready');

        Queue::assertPushed(GeneratePhotoWithModel::class, 3);
    }

    public function test_a_persistently_empty_analysis_without_user_notes_fails_loudly(): void
    {
        Queue::fake();

        PhotoBatchAnalyst::fake([[]]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'input_photo_ids' => [$photos[0]->id],
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));

        $batch->refresh();

        $this->assertSame(GenerationBatchStatus::Failed, $batch->status);
        $this->assertSame('Photo analysis requires manual review.', $batch->error);

        Queue::assertNotPushed(GeneratePhotoWithModel::class);
    }

    public function test_a_batch_with_no_inputs_fails_loudly(): void
    {
        Queue::fake();

        [$project, $owner] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'input_photo_ids' => [],
        ]);

        try {
            (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class), app(PhotoGenerationLifecycle::class));
        } catch (\RuntimeException) {
            $batch->update(['status' => GenerationBatchStatus::Failed]);
        }

        $this->assertSame(GenerationBatchStatus::Failed, $batch->fresh()->status);

        Queue::assertNotPushed(GeneratePhotoWithModel::class);
    }

    public function test_the_generation_job_stores_a_photo_with_model_metadata(): void
    {
        Image::fake();
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create([
                'input_photo_ids' => [$photos[0]->id],
                'analysis' => ['group_prompt' => 'Clean, polished countertop'],
            ]);

        $this->generationSlotJob(
            $batch,
            'openrouter',
            'google/gemini-2.5-flash-image',
            PhotoMode::Cleanup,
        )->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $photo = $batch->generatedPhotos()->sole();

        $this->assertSame(PhotoKind::Generated, $photo->kind);
        $this->assertStringStartsWith('generated_', $photo->path);
        $this->assertStringEndsWith('/original.png', $photo->path);
        $this->assertSame(PhotoProcessingStatus::Pending, $photo->processing_status);
        $this->assertSame('openrouter', $photo->provider);
        $this->assertSame('google/gemini-2.5-flash-image', $photo->model);
        $this->assertSame('Clean, polished countertop', $photo->text);
        $this->assertSame(PhotoMode::Cleanup, $photo->mode);
        $this->assertEquals(0.039, (float) $photo->cost_usd);
        $this->assertSame(PhotoCostSource::Estimate, $photo->cost_source);
        Storage::disk('s3')->assertExists($photo->path);

        Image::assertGenerated(fn ($prompt) => $prompt->contains('Clean, polished countertop'));
        Queue::assertPushed(GeneratePhotoDerivatives::class, 1);
    }

    public function test_a_downstream_derivative_dispatch_failure_never_deletes_a_committed_generated_source(): void
    {
        Image::fake();
        config([
            'queue.connections.lifecycle-database.table' => 'missing_jobs_table',
        ]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);
        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create([
                'input_photo_ids' => [$photos[0]->id],
                'analysis' => ['group_prompt' => 'Preserve the committed source'],
            ]);

        expect(fn () => $this->generationSlotJob(
            $batch,
            'openrouter',
            'google/gemini-2.5-flash-image',
            PhotoMode::Cleanup,
        )->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class)))
            ->toThrow(\RuntimeException::class);

        $this->assertFalse($batch->generatedPhotos()->exists());
        Image::assertNothingGenerated();
    }

    public function test_the_generation_job_records_the_actual_billed_cost_when_the_provider_reports_it(): void
    {
        Queue::fake();

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'images' => [[
                            'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII='],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 44444, 'completion_tokens' => 3072, 'cost' => 0.15],
            ]),
        ]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create([
                'input_photo_ids' => [$photos[0]->id],
                'analysis' => ['group_prompt' => 'Recreate the finished countertop'],
            ]);

        $this->generationSlotJob(
            $batch,
            'openrouter',
            'black-forest-labs/flux.2-pro',
            PhotoMode::Recreate,
        )->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $photo = $batch->generatedPhotos()->sole();

        $this->assertEquals(0.15, (float) $photo->cost_usd);
        $this->assertSame(PhotoCostSource::Provider, $photo->cost_source);
    }

    public function test_byok_image_generation_uses_the_customer_key_and_persists_actual_routing_cost_and_audit_metadata(): void
    {
        Queue::fake();
        Event::fake([GeneratingImage::class, ImageGenerated::class, RequestSending::class, ResponseReceived::class]);
        $http = app(ByokHttpFactory::class);
        $http->fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'model' => 'vendor/routed-image-model',
                'choices' => [[
                    'message' => [
                        'images' => [[
                            'image_url' => ['url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nWQAAAAASUVORK5CYII='],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'cost' => 0.27],
            ]),
        ]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);
        AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true]);
        AiProviderCredential::factory()->for($owner)->create([
            'secret' => 'customer-openrouter-key',
            'fingerprint' => hash('sha256', 'customer-openrouter-key'),
        ]);
        AiModelPreference::factory()->for($owner)->create([
            'purpose' => AiModelPurpose::Image,
            'mode' => AiModelMode::Custom,
            'model_ids' => ['vendor/requested-image-model'],
        ]);
        $batch = PhotoGenerationBatch::factory()->for($project)->for($owner, 'user')->create([
            'input_photo_ids' => [$photos[0]->id],
            'analysis' => ['group_prompt' => 'Create the final image.'],
        ]);

        $job = $this->generationSlotJob(
            $batch,
            'openrouter',
            'vendor/requested-image-model',
            PhotoMode::Recreate,
            usesByok: true,
        );
        $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $slot = PhotoGenerationSlot::findOrFail($job->slotId);
        $photo = $batch->generatedPhotos()->sole();
        $audit = AiOperationAudit::query()->where('event', AiAuditEvent::Succeeded->value)->sole();

        $this->assertSame('openrouter', $slot->actual_provider);
        $this->assertSame('vendor/routed-image-model', $slot->actual_model);
        $this->assertEquals(0.27, (float) $slot->actual_cost_usd);
        $this->assertSame(PhotoCostSource::Provider, $slot->actual_cost_source);
        $this->assertSame('vendor/routed-image-model', $photo->model);
        $this->assertEquals(0.27, (float) $photo->cost_usd);
        $this->assertSame(PhotoCostSource::Provider, $photo->cost_source);
        $this->assertSame('vendor/routed-image-model', $audit->model);
        $this->assertEquals(0.27, (float) $audit->cost_usd);
        $http->assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer customer-openrouter-key')
            && $request['model'] === 'vendor/requested-image-model');
        Event::assertNotDispatched(GeneratingImage::class);
        Event::assertNotDispatched(ImageGenerated::class);
        Event::assertNotDispatched(RequestSending::class);
        Event::assertNotDispatched(ResponseReceived::class);
    }

    public function test_a_revoked_byok_image_key_fails_before_the_provider_without_an_ambiguous_state(): void
    {
        Queue::fake();
        Http::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);
        AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true]);
        AiProviderCredential::factory()->for($owner)->create(['revoked_at' => now()]);
        AiModelPreference::factory()->for($owner)->create([
            'purpose' => AiModelPurpose::Image,
            'mode' => AiModelMode::Custom,
            'model_ids' => ['vendor/revoked-image-model'],
        ]);
        $batch = PhotoGenerationBatch::factory()->for($project)->for($owner, 'user')->create([
            'input_photo_ids' => [$photos[0]->id],
            'analysis' => ['group_prompt' => 'Do not contact a provider.'],
        ]);
        $job = $this->generationSlotJob(
            $batch,
            'openrouter',
            'vendor/revoked-image-model',
            PhotoMode::Cleanup,
            usesByok: true,
        );

        $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $slot = PhotoGenerationSlot::findOrFail($job->slotId);

        $this->assertSame(PhotoGenerationSlotStatus::Failed, $slot->status);
        $this->assertSame('pre_provider_ai_unavailable', $slot->failure_code);
        Http::assertNothingSent();
    }

    public function test_a_concurrency_rejection_requeues_a_byok_image_before_contacting_the_provider(): void
    {
        Queue::fake();
        Http::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);
        AiAccountSetting::factory()->for($owner)->create(['byok_enabled' => true, 'max_concurrency' => 1]);
        AiProviderCredential::factory()->for($owner)->create(['secret' => 'customer-openrouter-key']);
        AiModelPreference::factory()->for($owner)->create([
            'purpose' => AiModelPurpose::Image,
            'mode' => AiModelMode::Custom,
            'model_ids' => ['vendor/deferred-image-model'],
        ]);
        AiOperationAudit::query()->create([
            'operation_id' => fake()->uuid(),
            'account_id' => $owner->id,
            'actor_user_id' => $owner->id,
            'event' => AiAuditEvent::Started,
            'purpose' => AiModelPurpose::Image,
            'provider' => 'openrouter',
            'model' => 'vendor/in-flight-image-model',
            'occurred_at' => now(),
        ]);
        $batch = PhotoGenerationBatch::factory()->for($project)->for($owner, 'user')->create([
            'input_photo_ids' => [$photos[0]->id],
            'analysis' => ['group_prompt' => 'Wait for the in-flight operation.'],
        ]);
        $job = $this->generationSlotJob(
            $batch,
            'openrouter',
            'vendor/deferred-image-model',
            PhotoMode::Cleanup,
            usesByok: true,
        )->withFakeQueueInteractions();

        $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $this->assertSame(PhotoGenerationSlotStatus::Queued, PhotoGenerationSlot::findOrFail($job->slotId)->status);
        $job->assertReleased((int) config('account-ai.concurrency_retry_seconds'));
        Http::assertNothingSent();
    }

    public function test_an_unknown_model_fails_before_contacting_a_fallback_provider(): void
    {
        Image::fake();
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create([
                'input_photo_ids' => [$photos[0]->id],
                'analysis' => ['group_prompt' => 'Clean it up'],
            ]);

        $job = $this->generationSlotJob(
            $batch,
            'openrouter',
            'not/profiled',
            PhotoMode::Cleanup,
        );
        $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $this->assertFalse($batch->generatedPhotos()->exists());
        $this->assertSame(PhotoGenerationSlotStatus::Failed, PhotoGenerationSlot::findOrFail($job->slotId)->status);
        Image::assertNothingGenerated();
    }

    public function test_pre_provider_model_failure_is_terminal_without_throwing_or_retrying(): void
    {
        Image::fake();
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create([
                'input_photo_ids' => [$photos[0]->id],
                'analysis' => ['group_prompt' => 'Clean it up'],
            ]);

        $job = $this->generationSlotJob(
            $batch,
            'openrouter',
            'not/profiled',
            PhotoMode::Cleanup,
        );
        $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $this->assertSame(PhotoGenerationSlotStatus::Failed, PhotoGenerationSlot::findOrFail($job->slotId)->status);
        Image::assertNothingGenerated();
    }

    public function test_provider_failure_details_never_reach_the_failed_exception_or_database(): void
    {
        Queue::fake();

        $providerSecret = 'provider-debug-private-payload';
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'error' => ['message' => $providerSecret],
            ], 500),
        ]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);
        $batch = PhotoGenerationBatch::factory()->for($project)->for($owner)->create([
            'input_photo_ids' => [$photos[0]->id],
            'analysis' => ['group_prompt' => 'Private customer prompt'],
        ]);

        $job = $this->generationSlotJob(
            $batch,
            'openrouter',
            'black-forest-labs/flux.2-pro',
            PhotoMode::Cleanup,
        );
        $job->handle(app(ImageModelCatalog::class), app(PhotoGenerationLifecycle::class));

        $this->assertSame(PhotoGenerationSlotStatus::Ambiguous, PhotoGenerationSlot::findOrFail($job->slotId)->status);
        $this->assertStringNotContainsString($providerSecret, (string) json_encode($batch->fresh()?->getAttributes()));
    }
}
