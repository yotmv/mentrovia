<?php

namespace Tests\Feature;

use App\Ai\Agents\PhotoDescriber;
use App\Enums\PhotoProcessingStatus;
use App\Enums\PhotoTextSource;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\GeneratePhotoDerivatives;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PhotoUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['photostudio.disk' => 's3']);
    }

    public function test_uploads_are_stored_with_the_uploaded_prefix(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->set('uploads', [
                UploadedFile::fake()->image('site-photo.jpg'),
                UploadedFile::fake()->image('second.png'),
            ])
            ->set('uploadDescription', 'Dusty island slab, unfinished seam')
            ->call('saveUploads')
            ->assertHasNoErrors();

        $photos = $project->uploadedPhotos()->get();

        $this->assertCount(2, $photos);

        foreach ($photos as $photo) {
            $this->assertStringStartsWith('uploaded_', $photo->path);
            $this->assertMatchesRegularExpression('#/original\.(jpg|jpeg|png)$#', $photo->path);
            $this->assertSame('s3', $photo->disk);
            $this->assertSame(PhotoProcessingStatus::Pending, $photo->processing_status);
            $this->assertSame('Dusty island slab, unfinished seam', $photo->text);
            $this->assertSame(PhotoTextSource::User, $photo->text_source);
            Storage::disk('s3')->assertExists($photo->path);
        }

        Queue::assertPushed(GeneratePhotoDerivatives::class, 2);
        Queue::assertNotPushed(DescribeUploadedPhoto::class);
    }

    public function test_uploads_without_text_queue_derivative_processing_which_chains_captioning(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->set('uploads', [UploadedFile::fake()->image('site-photo.jpg')])
            ->call('saveUploads')
            ->assertHasNoErrors();

        $photo = $project->uploadedPhotos()->sole();

        $this->assertNull($photo->text);
        $this->assertNull($photo->text_source);

        // Captioning is dispatched by GeneratePhotoDerivatives after the
        // normalized LLM input exists, not directly from the upload action.
        Queue::assertPushed(GeneratePhotoDerivatives::class, 1);
        Queue::assertNotPushed(DescribeUploadedPhoto::class);
    }

    public function test_uploads_reject_disallowed_types_and_oversized_dimensions(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->set('uploads', [UploadedFile::fake()->create('document.pdf', 500, 'application/pdf')])
            ->call('saveUploads')
            ->assertHasErrors('uploads.0');

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->set('uploads', [UploadedFile::fake()->image('huge.jpg', 9000, 200)])
            ->call('saveUploads')
            ->assertHasErrors('uploads.0');

        $this->assertSame(0, $project->photos()->count());
        Queue::assertNotPushed(GeneratePhotoDerivatives::class);
    }

    public function test_the_describe_job_fills_in_an_ai_description(): void
    {
        config([
            'ai.providers.openrouter.key' => 'test-key',
            'photostudio.analysis.provider' => 'openrouter',
        ]);

        PhotoDescriber::fake([
            ['description' => 'A granite slab covered in polishing dust.'],
        ]);

        $photo = Photo::factory()->create([
            'disk' => 's3',
            'text' => null,
            'text_source' => null,
        ]);

        Storage::disk('s3')->put($photo->path, 'fake-image-bytes');

        (new DescribeUploadedPhoto($photo))->handle();

        $photo->refresh();

        $this->assertSame('A granite slab covered in polishing dust.', $photo->text);
        $this->assertSame(PhotoTextSource::Auto, $photo->text_source);
    }

    public function test_the_describe_job_never_overwrites_user_text(): void
    {
        PhotoDescriber::fake();

        $photo = Photo::factory()->create([
            'text' => 'User wrote this',
            'text_source' => PhotoTextSource::User,
        ]);

        (new DescribeUploadedPhoto($photo))->handle();

        $this->assertSame('User wrote this', $photo->fresh()->text);

        PhotoDescriber::assertNeverPrompted();
    }
}
