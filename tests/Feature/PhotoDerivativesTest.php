<?php

namespace Tests\Feature;

use App\Enums\PhotoProcessingStatus;
use App\Images\PhotoDerivativeService;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\GeneratePhotoDerivatives;
use App\Models\Photo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

        app(PhotoDerivativeService::class)->process($photo);

        Process::assertRan(function (PendingProcess $process) use ($photo) {
            $config = json_decode($process->command[4], true);

            return str_contains($process->command[1], 'create-portfolio-derivatives.mjs')
                && array_keys($config['variants']) === array_keys(config('photostudio.processing.variants')[$photo->kind->value])
                && $config['max_source_dimension'] === config('photostudio.processing.max_source_dimension');
        });

        $photo->refresh();
        $directory = dirname($photo->path);

        $this->assertSame($directory.'/llm-input.jpg', $photo->derivativePath('llm-input'));
        $this->assertSame(4000, $photo->width);
        $this->assertSame(5_000_000, (int) $photo->size_bytes);
        $this->assertSame(150_000, $photo->derivativeSizeBytes('card'));
        Storage::disk('s3')->assertExists($directory.'/llm-input.jpg');
        Storage::disk('s3')->assertExists($directory.'/card.webp');
        Storage::disk('s3')->assertExists($directory.'/thumb.webp');
    }

    public function test_the_service_fails_when_the_worker_fails(): void
    {
        $photo = Photo::factory()->create();

        Storage::disk('s3')->put($photo->path, 'source-bytes');

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'sharp exploded', exitCode: 1),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sharp exploded');

        app(PhotoDerivativeService::class)->process($photo);
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
            ->once();

        (new GeneratePhotoDerivatives($photo))->handle(app(PhotoDerivativeService::class));

        $photo->refresh();

        $this->assertSame(PhotoProcessingStatus::Ready, $photo->processing_status);
        $this->assertNotNull($photo->processed_at);

        Queue::assertPushed(DescribeUploadedPhoto::class, 1);
    }

    public function test_the_job_skips_captioning_when_a_description_exists(): void
    {
        Queue::fake();

        $photo = Photo::factory()->create(['processing_status' => PhotoProcessingStatus::Pending]);

        $this->mock(PhotoDerivativeService::class)->shouldReceive('process')->once();

        (new GeneratePhotoDerivatives($photo))->handle(app(PhotoDerivativeService::class));

        Queue::assertNotPushed(DescribeUploadedPhoto::class);
    }

    public function test_the_job_records_a_readable_error_and_rethrows_on_failure(): void
    {
        Queue::fake();

        $photo = Photo::factory()->create(['processing_status' => PhotoProcessingStatus::Pending]);

        $this->mock(PhotoDerivativeService::class)
            ->shouldReceive('process')
            ->andThrow(new RuntimeException('libvips could not decode the file'));

        try {
            (new GeneratePhotoDerivatives($photo))->handle(app(PhotoDerivativeService::class));
            $this->fail('Expected the job to rethrow.');
        } catch (RuntimeException) {
            // Expected: rethrowing lets the queue retry the job.
        }

        $photo->refresh();

        $this->assertSame(PhotoProcessingStatus::Failed, $photo->processing_status);
        $this->assertStringContainsString('libvips could not decode', $photo->processing_error);
        Queue::assertNotPushed(DescribeUploadedPhoto::class);
    }

    public function test_urls_prefer_derivatives_with_a_safe_fallback_chain(): void
    {
        $processed = Photo::factory()->withDerivatives()->create();

        $this->assertStringContainsString('thumb.webp', $processed->url('thumb'));
        $this->assertStringContainsString('card.webp', $processed->url('does-not-exist'));

        $unprocessed = Photo::factory()->create([
            'processing_status' => PhotoProcessingStatus::Pending,
            'derivatives' => null,
        ]);

        $this->assertStringContainsString('original.jpg', $unprocessed->url('thumb'));
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

        app(PhotoDerivativeService::class)->process($photo);

        $photo->refresh();

        $this->assertSame(1200, $photo->width);
        $this->assertNotNull($photo->derivativePath('llm-input'));
        $this->assertNotNull($photo->derivativePath('thumb'));
        Storage::disk('s3')->assertExists($photo->derivativePath('card'));
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
}
