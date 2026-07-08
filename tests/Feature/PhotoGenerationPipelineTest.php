<?php

namespace Tests\Feature;

use App\Ai\Agents\PhotoBatchAnalyst;
use App\Ai\Images\Exceptions\UnknownImageModelException;
use App\Ai\Images\ImageModelCatalog;
use App\Ai\Images\ImageModelChooser;
use App\Enums\GenerationBatchStatus;
use App\Enums\PhotoCostSource;
use App\Enums\PhotoKind;
use App\Enums\PhotoMode;
use App\Enums\PhotoProcessingStatus;
use App\Jobs\GeneratePhotoDerivatives;
use App\Jobs\GeneratePhotoWithModel;
use App\Jobs\RunPhotoGenerationBatch;
use App\Models\Photo;
use App\Models\PhotoGenerationBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

    public function test_the_batch_job_analyzes_selects_three_models_and_fans_out(): void
    {
        Bus::fake();

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

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class));

        $batch->refresh();

        $this->assertSame(GenerationBatchStatus::Processing, $batch->status);
        $this->assertSame('Granite countertop', $batch->analysis['subject']);
        $this->assertCount(3, $batch->selected_models);

        Bus::assertBatched(function ($pendingBatch) {
            return $pendingBatch->jobs->count() === 3
                && $pendingBatch->jobs->every(
                    fn ($job) => $job instanceof GeneratePhotoWithModel
                        && $job->prompt === 'Produce a clean, polished granite countertop photo.'
                        && $job->mode === PhotoMode::Cleanup
                );
        });
    }

    public function test_a_recreate_verdict_switches_the_batch_mode(): void
    {
        Bus::fake();

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

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class));

        Bus::assertBatched(fn ($pendingBatch) => $pendingBatch->jobs->every(
            fn ($job) => $job->mode === PhotoMode::Recreate
        ));
    }

    public function test_an_empty_analysis_response_is_retried_once(): void
    {
        Bus::fake();

        PhotoBatchAnalyst::fake([[], [
            'subject' => 'Granite countertop',
            'intended_final_state' => 'Clean, polished countertop',
            'style_notes' => 'Warm showroom lighting',
            'group_prompt' => 'Produce a clean, polished granite countertop photo.',
            'images' => [
                ['index' => 0, 'verdict' => 'cleanup', 'defects' => ['dust'], 'prompt' => 'Clean image 0', 'text' => 'Dusty slab'],
            ],
        ]]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'input_photo_ids' => [$photos[0]->id],
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class));

        $this->assertSame('Granite countertop', $batch->fresh()->analysis['subject']);

        Bus::assertBatched(fn ($pendingBatch) => $pendingBatch->jobs->every(
            fn ($job) => $job->prompt === 'Produce a clean, polished granite countertop photo.'
        ));
    }

    public function test_a_persistently_empty_analysis_falls_back_to_the_user_notes(): void
    {
        Bus::fake();

        PhotoBatchAnalyst::fake([[], []]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'user_text' => 'Make it showroom ready',
            'input_photo_ids' => [$photos[0]->id],
        ]);

        (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class));

        Bus::assertBatched(fn ($pendingBatch) => $pendingBatch->jobs->every(
            fn ($job) => $job->prompt === 'Make it showroom ready'
        ));
    }

    public function test_a_persistently_empty_analysis_without_user_notes_fails_loudly(): void
    {
        Bus::fake();

        PhotoBatchAnalyst::fake([[], []]);

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'input_photo_ids' => [$photos[0]->id],
        ]);

        try {
            (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class));
            $this->fail('Expected the batch job to throw.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $batch->refresh();

        $this->assertSame(GenerationBatchStatus::Failed, $batch->status);
        $this->assertStringContainsString('generation prompt', $batch->error);

        Bus::assertNothingBatched();
    }

    public function test_a_batch_with_no_inputs_fails_loudly(): void
    {
        Bus::fake();

        [$project, $owner] = $this->projectWithUploads(1);

        $batch = $project->generationBatches()->create([
            'user_id' => $owner->id,
            'input_photo_ids' => [],
        ]);

        try {
            (new RunPhotoGenerationBatch($batch))->handle(app(ImageModelChooser::class));
            $this->fail('Expected the batch job to throw.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(GenerationBatchStatus::Failed, $batch->fresh()->status);

        Bus::assertNothingBatched();
    }

    public function test_the_generation_job_stores_a_photo_with_model_metadata(): void
    {
        Image::fake();
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create(['input_photo_ids' => [$photos[0]->id]]);

        (new GeneratePhotoWithModel(
            $batch,
            'openrouter',
            'google/gemini-2.5-flash-image',
            'Clean, polished countertop',
            PhotoMode::Cleanup,
        ))->handle(app(ImageModelCatalog::class));

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

    public function test_the_generation_job_records_the_actual_billed_cost_when_the_provider_reports_it(): void
    {
        Queue::fake();

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'images' => [[
                            'image_url' => ['url' => 'data:image/png;base64,'.base64_encode('generated-bytes')],
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
            ->create(['input_photo_ids' => [$photos[0]->id]]);

        (new GeneratePhotoWithModel(
            $batch,
            'openrouter',
            'black-forest-labs/flux.2-pro',
            'Recreate the finished countertop',
            PhotoMode::Recreate,
        ))->handle(app(ImageModelCatalog::class));

        $photo = $batch->generatedPhotos()->sole();

        $this->assertEquals(0.15, (float) $photo->cost_usd);
        $this->assertSame(PhotoCostSource::Provider, $photo->cost_source);
    }

    public function test_the_generation_job_falls_back_to_the_substitute_model(): void
    {
        Image::fake();
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create(['input_photo_ids' => [$photos[0]->id]]);

        (new GeneratePhotoWithModel(
            $batch,
            'openrouter',
            'not/profiled',
            'Clean it up',
            PhotoMode::Cleanup,
            ['provider' => 'openrouter', 'model' => 'google/gemini-2.5-flash-image'],
        ))->handle(app(ImageModelCatalog::class));

        $photo = $batch->generatedPhotos()->sole();

        $this->assertSame('google/gemini-2.5-flash-image', $photo->model);
    }

    public function test_the_generation_job_rethrows_when_no_fallback_exists(): void
    {
        Image::fake();
        Queue::fake();

        [$project, $owner, $photos] = $this->projectWithUploads(1);

        $batch = PhotoGenerationBatch::factory()
            ->for($project)
            ->for($owner, 'user')
            ->create(['input_photo_ids' => [$photos[0]->id]]);

        $this->expectException(UnknownImageModelException::class);

        (new GeneratePhotoWithModel(
            $batch,
            'openrouter',
            'not/profiled',
            'Clean it up',
            PhotoMode::Cleanup,
        ))->handle(app(ImageModelCatalog::class));
    }
}
