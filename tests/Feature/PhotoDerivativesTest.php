<?php

namespace Tests\Feature;

use App\Enums\PhotoProcessingStatus;
use App\Images\PhotoDerivativeResult;
use App\Images\PhotoDerivativeService;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\GeneratePhotoDerivatives;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use App\Services\PhotoGenerationLifecycle;
use App\Services\PhotoStorageCleanupService;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PhotoDerivativesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['photostudio.disk' => 's3']);
    }

    public function test_the_service_runs_the_sharp_worker_and_stores_derivatives_beside_the_source(): void
    {
        $photo = Photo::factory()->create();

        Storage::disk('s3')->put($photo->path, 'source-bytes');

        Process::fake(function (PendingProcess $process) {
            [, , , $outputDirectory] = array_pad($process->command, 4, null);

            File::ensureDirectoryExists($outputDirectory);
            File::put($outputDirectory.'/llm-input.jpg', 'llm-bytes');
            File::put($outputDirectory.'/card.webp', 'card-bytes');
            File::put($outputDirectory.'/thumb.webp', 'thumb-bytes');

            return Process::result(json_encode([
                'source' => ['width' => 4000, 'height' => 3000, 'format' => 'jpeg', 'size_bytes' => 5_000_000],
                'variants' => [
                    'llm-input' => ['file' => 'llm-input.jpg', 'width' => 2048, 'height' => 1536, 'size_bytes' => 900_000],
                    'card' => ['file' => 'card.webp', 'width' => 1200, 'height' => 900, 'size_bytes' => 150_000],
                    'thumb' => ['file' => 'thumb.webp', 'width' => 500, 'height' => 500, 'size_bytes' => 40_000],
                ],
            ]));
        });

        $result = app(PhotoDerivativeService::class)->process($photo);

        Process::assertRan(function (PendingProcess $process) use ($photo) {
            $config = json_decode($process->command[4], true);

            return str_contains($process->command[1], 'create-portfolio-derivatives.mjs')
                && array_keys($config['variants']) === array_keys(config('photostudio.processing.variants')[$photo->kind->value])
                && $config['max_source_dimension'] === config('photostudio.processing.max_source_dimension');
        });

        $directory = dirname($photo->path);

        $this->assertStringStartsWith($directory.'/derivatives/', $result->derivatives['llm-input']['path']);
        $this->assertSame(4000, $result->width);
        $this->assertSame(5_000_000, $result->sizeBytes);
        $this->assertSame(150_000, $result->derivatives['card']['size_bytes']);
        Storage::disk('s3')->assertExists($result->derivatives['llm-input']['path']);
        Storage::disk('s3')->assertExists($result->derivatives['card']['path']);
        Storage::disk('s3')->assertExists($result->derivatives['thumb']['path']);
        $this->assertNull($photo->fresh()->derivatives);

        $retryResult = app(PhotoDerivativeService::class)->process($photo);

        $this->assertNotSame(
            $result->derivatives['card']['path'],
            $retryResult->derivatives['card']['path'],
        );
        Storage::disk('s3')->assertExists($result->derivatives['card']['path']);
        Storage::disk('s3')->assertExists($retryResult->derivatives['card']['path']);
    }

    public function test_the_service_fails_when_the_worker_fails(): void
    {
        $photo = Photo::factory()->create();

        Storage::disk('s3')->put($photo->path, 'source-bytes');

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'sharp exploded', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Photo derivative generation failed.');

        app(PhotoDerivativeService::class)->process($photo);
    }

    public function test_the_service_removes_partially_stored_derivatives_when_processing_fails(): void
    {
        $photo = Photo::factory()->create();
        Storage::disk('s3')->put($photo->path, 'source-bytes');

        Process::fake(function (PendingProcess $process) {
            [, , , $outputDirectory] = array_pad($process->command, 4, null);

            File::ensureDirectoryExists($outputDirectory);
            File::put($outputDirectory.'/llm-input.jpg', 'llm-bytes');

            return Process::result(json_encode([
                'source' => ['width' => 4000, 'height' => 3000, 'format' => 'jpeg', 'size_bytes' => 5_000_000],
                'variants' => [
                    'llm-input' => ['file' => 'llm-input.jpg', 'width' => 2048, 'height' => 1536, 'size_bytes' => 900_000],
                    'card' => ['file' => 'missing-card.webp', 'width' => 1200, 'height' => 900, 'size_bytes' => 150_000],
                ],
            ]));
        });

        try {
            app(PhotoDerivativeService::class)->process($photo);
            $this->fail('Expected derivative processing to fail.');
        } catch (RuntimeException) {
            // The first uploaded derivative must be compensated below.
        }

        $this->assertSame([$photo->path], Storage::disk('s3')->allFiles());
    }

    public function test_the_job_marks_the_photo_ready_and_queues_auto_captioning(): void
    {
        Queue::fake();

        $photo = Photo::factory()->create([
            'processing_status' => PhotoProcessingStatus::Pending,
            'text' => null,
            'text_source' => null,
        ]);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->once()
            ->andReturn($this->fakeResult($photo));

        (new GeneratePhotoDerivatives($photo))->handle(app(PhotoDerivativeService::class), app(PhotoGenerationLifecycle::class));

        $photo->refresh();

        $this->assertSame(PhotoProcessingStatus::Ready, $photo->processing_status);
        $this->assertNotNull($photo->processed_at);

        Queue::assertPushed(DescribeUploadedPhoto::class, 1);
    }

    public function test_the_job_skips_captioning_when_a_description_exists(): void
    {
        Queue::fake();

        $photo = Photo::factory()->create(['processing_status' => PhotoProcessingStatus::Pending]);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->once()
            ->andReturn($this->fakeResult($photo));

        (new GeneratePhotoDerivatives($photo))->handle(app(PhotoDerivativeService::class), app(PhotoGenerationLifecycle::class));

        Queue::assertNotPushed(DescribeUploadedPhoto::class);
    }

    public function test_caption_enqueue_failure_rolls_back_derivative_metadata_and_cleans_staged_files(): void
    {
        $photo = Photo::factory()->uncaptioned()->create([
            'processing_status' => PhotoProcessingStatus::Pending,
        ]);
        $result = $this->fakeResult($photo);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->once()
            ->andReturnUsing(function () use ($result) {
                config(['queue.connections.lifecycle-database.table' => 'missing_jobs_table']);

                return $result;
            });

        expect(fn () => (new GeneratePhotoDerivatives($photo))->handle(
            app(PhotoDerivativeService::class),
            app(PhotoGenerationLifecycle::class),
        ))->toThrow(RuntimeException::class, 'Photo derivative generation failed.');

        $this->assertSame(PhotoProcessingStatus::Failed, $photo->fresh()->processing_status);
        $this->assertNull($photo->fresh()->derivativePath('card'));
        Storage::disk('s3')->assertMissing($result->derivatives['card']['path']);
    }

    public function test_the_job_records_a_readable_error_and_rethrows_on_failure(): void
    {
        Queue::fake();
        Log::spy();

        $photo = Photo::factory()->create(['processing_status' => PhotoProcessingStatus::Pending]);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->andThrow(new RuntimeException('libvips could not decode the file'));

        try {
            (new GeneratePhotoDerivatives($photo))->handle(app(PhotoDerivativeService::class), app(PhotoGenerationLifecycle::class));
            $this->fail('Expected the job to rethrow.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Photo derivative generation failed.', $exception->getMessage());
        }

        $photo->refresh();

        $this->assertSame(PhotoProcessingStatus::Failed, $photo->processing_status);
        $this->assertSame('Photo processing failed. Retrying automatically.', $photo->processing_error);
        $this->assertStringNotContainsString('libvips', $photo->processing_error);
        Log::shouldHaveReceived('warning')->with(
            'Photo derivative job failed.',
            \Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), 'libvips')),
        );
        Queue::assertNotPushed(DescribeUploadedPhoto::class);
    }

    public function test_a_derivative_retry_commits_a_new_version_before_deleting_the_previous_version(): void
    {
        Queue::fake();

        $photo = Photo::factory()->withDerivatives()->create();
        $oldPath = $photo->derivativePath('card');
        Storage::disk('s3')->put($oldPath, 'old-card');
        $result = $this->fakeResult($photo);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->once()
            ->andReturn($result);

        (new GeneratePhotoDerivatives($photo))->handle(
            app(PhotoDerivativeService::class),
            app(PhotoGenerationLifecycle::class),
        );

        $this->assertSame($result->derivatives['card']['path'], $photo->fresh()->derivativePath('card'));
        Storage::disk('s3')->assertExists($result->derivatives['card']['path']);
        Storage::disk('s3')->assertMissing($oldPath);
    }

    public function test_failed_old_version_cleanup_is_tracked_durably_for_retry(): void
    {
        Queue::fake();

        $photo = Photo::factory()->withDerivatives()->create();
        $oldPath = $photo->derivativePath('card');
        $result = $this->fakeResult($photo);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->once()
            ->andReturn($result);

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andReturnFalse();
        $disk->shouldReceive('exists')->andReturnTrue();

        $filesystems = \Mockery::mock(FilesystemFactory::class);
        $filesystems->shouldReceive('disk')->with('s3')->andReturn($disk);
        $this->app->instance(FilesystemFactory::class, $filesystems);

        (new GeneratePhotoDerivatives($photo))->handle(
            app(PhotoDerivativeService::class),
            app(PhotoGenerationLifecycle::class),
            app(PhotoStorageCleanupService::class),
        );

        $this->assertDatabaseHas('photo_storage_cleanups', [
            'disk' => 's3',
            'path' => $oldPath,
            'completed_at' => null,
        ]);
        $this->assertSame($result->derivatives['card']['path'], $photo->fresh()->derivativePath('card'));
    }

    public function test_a_manual_derivative_retry_remains_pending_when_queue_dispatch_fails(): void
    {
        config([
            'queue.connections.lifecycle-database.table' => 'missing_jobs_table',
        ]);

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->for($project)->for($owner)->create([
            'processing_status' => PhotoProcessingStatus::Failed,
            'processing_error' => 'Generic processing failure.',
        ]);

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('retryProcessing', $photo->id)
            ->assertHasErrors('processing');

        $photo->refresh();

        $this->assertSame(PhotoProcessingStatus::Failed, $photo->processing_status);
        $this->assertSame('Generic processing failure.', $photo->processing_error);
        $this->assertNull($photo->derivatives_enqueued_at);
    }

    public function test_urls_prefer_derivatives_with_a_safe_fallback_chain(): void
    {
        $processed = Photo::factory()->withDerivatives()->create();

        $this->assertSame(route('projects.photos.show', [
            'project' => $processed->project_id,
            'photo' => $processed,
            'variant' => 'thumb',
        ]), $processed->url('thumb'));
        $this->assertSame(route('projects.photos.show', [
            'project' => $processed->project_id,
            'photo' => $processed,
            'variant' => 'card',
        ]), $processed->url('does-not-exist'));

        $unprocessed = Photo::factory()->create([
            'processing_status' => PhotoProcessingStatus::Pending,
            'derivatives' => null,
        ]);

        $this->assertSame(route('projects.photos.show', [
            'project' => $unprocessed->project_id,
            'photo' => $unprocessed,
        ]), $unprocessed->url('thumb'));
    }

    public function test_prune_command_removes_expired_originals_and_repoints_to_the_llm_input(): void
    {
        $expired = Photo::factory()->withDerivatives()->create(['created_at' => now()->subDays(45)]);
        $recent = Photo::factory()->withDerivatives()->create(['created_at' => now()->subDays(2)]);
        $generated = Photo::factory()->generated()->withDerivatives()->create(['created_at' => now()->subDays(45)]);

        foreach ([$expired, $recent, $generated] as $photo) {
            Storage::disk('s3')->put($photo->path, 'original-bytes');
        }

        $this->artisan('photos:prune-originals', ['--days' => 30])->assertSuccessful();

        Storage::disk('s3')->assertMissing($expired->path);
        $this->assertSame($expired->fresh()->path, $expired->derivativePath('llm-input'));

        Storage::disk('s3')->assertExists($recent->path);
        Storage::disk('s3')->assertExists($generated->path);
        $this->assertSame($generated->path, $generated->fresh()->path);
    }

    public function test_prune_command_dry_run_deletes_nothing(): void
    {
        $expired = Photo::factory()->withDerivatives()->create(['created_at' => now()->subDays(45)]);

        Storage::disk('s3')->put($expired->path, 'original-bytes');

        $this->artisan('photos:prune-originals', ['--days' => 30, '--dry-run' => true])->assertSuccessful();

        Storage::disk('s3')->assertExists($expired->path);
        $this->assertSame($expired->path, $expired->fresh()->path);
    }

    public function test_the_real_sharp_worker_produces_the_expected_files(): void
    {
        $node = $this->resolveNodeBinary();

        if ($node === null || ! File::exists(base_path('node_modules/sharp/package.json'))) {
            $this->markTestSkipped('Node with the sharp module is not available.');
        }

        config(['photostudio.processing.node_binary' => $node]);

        $image = imagecreatetruecolor(1200, 800);
        imagefilledrectangle($image, 0, 0, 1200, 800, imagecolorallocate($image, 100, 120, 140));

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();

        $photo = Photo::factory()->create();
        Storage::disk('s3')->put($photo->path, $jpeg);

        $result = app(PhotoDerivativeService::class)->process($photo);

        $this->assertSame(1200, $result->width);
        $this->assertArrayHasKey('llm-input', $result->derivatives);
        $this->assertArrayHasKey('thumb', $result->derivatives);
        Storage::disk('s3')->assertExists($result->derivatives['card']['path']);
    }

    protected function resolveNodeBinary(): ?string
    {
        $which = trim((string) shell_exec('command -v node 2>/dev/null'));

        if ($which !== '') {
            return $which;
        }

        $nvmBinaries = glob(($_SERVER['HOME'] ?? '/home/'.get_current_user()).'/.nvm/versions/node/*/bin/node') ?: [];

        return $nvmBinaries === [] ? null : end($nvmBinaries);
    }

    private function fakeResult(Photo $photo): PhotoDerivativeResult
    {
        $path = dirname($photo->path).'/derivatives/test-attempt/card.webp';
        Storage::disk($photo->disk)->put($path, 'card');

        return new PhotoDerivativeResult(
            derivatives: [
                'card' => [
                    'path' => $path,
                    'width' => 1200,
                    'height' => 800,
                    'size_bytes' => 100,
                ],
            ],
            storedPaths: [$path],
            width: 1200,
            height: 800,
            sizeBytes: 100,
        );
    }
}
