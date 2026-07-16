<?php

namespace Tests\Feature;

use App\Enums\PhotoProcessingStatus;
use App\Enums\ProjectPermission;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class GeneratedPhotoGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        config(['photostudio.disk' => 's3']);
    }

    public function test_opening_the_gallery_defaults_to_the_hero_variant_and_lists_every_version(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->withDerivatives()->for($project)->for($owner, 'user')->create();

        $component = Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $photo->id)
            ->assertSet('galleryPhotoId', $photo->id)
            ->assertSet('galleryVariant', 'hero')
            ->assertSee('Master (WebP)')
            ->assertSee('Hero (JPG)')
            ->assertSee('Thumbnail (WebP)')
            ->assertSee('Download');

        $this->assertSame(
            ['original', 'master', 'hero', 'hero-jpg', 'card', 'thumb'],
            array_keys($component->instance()->galleryVariants),
        );
    }

    public function test_selecting_a_variant_switches_the_main_image(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->withDerivatives()->for($project)->for($owner, 'user')->create();

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $photo->id)
            ->call('selectGalleryVariant', 'thumb')
            ->assertSet('galleryVariant', 'thumb')
            ->call('selectGalleryVariant', 'not-a-variant')
            ->assertSet('galleryVariant', 'thumb');
    }

    public function test_an_unprocessed_photo_only_offers_the_original(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->for($project)->for($owner, 'user')->create([
            'processing_status' => PhotoProcessingStatus::Pending,
            'derivatives' => null,
        ]);

        $component = Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $photo->id)
            ->assertSet('galleryVariant', 'original');

        $this->assertSame(['original'], array_keys($component->instance()->galleryVariants));
    }

    public function test_the_gallery_rejects_photos_from_other_projects(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $foreignPhoto = Photo::factory()->generated()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $foreignPhoto->id);
    }

    public function test_deleting_an_image_set_removes_the_row_and_every_stored_file(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->withDerivatives()->for($project)->for($owner, 'user')->create();

        $directory = dirname($photo->path);

        Storage::disk('s3')->put($photo->path, 'original-bytes');
        foreach ($photo->derivatives as $derivative) {
            Storage::disk('s3')->put($derivative['path'], 'derivative-bytes');
        }

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $photo->id)
            ->call('deleteGalleryPhoto')
            ->assertSet('galleryPhotoId', null);

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertSame([], Storage::disk('s3')->allFiles($directory));
    }

    public function test_a_read_only_user_cannot_delete_an_image_set(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $project->sharedUsers()->attach($viewer->id, ['permission' => ProjectPermission::Read->value]);

        $photo = Photo::factory()->generated()->withDerivatives()->for($project)->for($owner, 'user')->create();

        Livewire::actingAs($viewer)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $photo->id)
            ->call('deleteGalleryPhoto')
            ->assertForbidden();

        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
    }

    public function test_gallery_deletion_commits_metadata_and_cleanup_outbox_before_touching_storage(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->withDerivatives()->for($project)->for($owner)->create();

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andReturnFalse();
        $disk->shouldReceive('exists')->andReturnTrue();

        $filesystems = \Mockery::mock(FilesystemFactory::class);
        $filesystems->shouldReceive('disk')->with('s3')->andReturn($disk);
        $this->app->instance(FilesystemFactory::class, $filesystems);

        Livewire::actingAs($owner)
            ->test('projects.show', ['project' => $project])
            ->call('openGallery', $photo->id)
            ->call('deleteGalleryPhoto');

        $this->assertDatabaseMissing('photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('photo_storage_cleanups', [
            'disk' => 's3',
            'path' => $photo->path,
            'completed_at' => null,
        ]);
    }

    public function test_download_urls_resolve_for_variants_and_the_original(): void
    {
        $photo = Photo::factory()->generated()->withDerivatives()->create();

        $this->assertSame(
            route('projects.photos.show', [
                'project' => $photo->project_id,
                'photo' => $photo,
                'download' => 1,
            ]),
            $photo->downloadUrl(),
        );
        $this->assertSame(
            route('projects.photos.show', [
                'project' => $photo->project_id,
                'photo' => $photo,
                'variant' => 'hero',
                'download' => 1,
            ]),
            $photo->downloadUrl('hero'),
        );
    }

    public function test_an_owner_can_read_a_private_photo_through_the_application(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->withDerivatives()->for($project)->for($owner, 'user')->create();

        Storage::disk('s3')->put($photo->derivativePath('hero'), 'private-image-bytes');

        $this->actingAs($owner)
            ->get($photo->url('hero'))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertStreamedContent('private-image-bytes');
    }

    public function test_a_shared_viewer_can_read_but_a_foreign_user_cannot_read_a_private_photo(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $foreignUser = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $project->sharedUsers()->attach($viewer->id, ['permission' => ProjectPermission::Read->value]);
        $photo = Photo::factory()->generated()->for($project)->for($owner, 'user')->create();

        Storage::disk('s3')->put($photo->path, 'private-image-bytes');

        $this->actingAs($viewer)
            ->get($photo->url())
            ->assertOk()
            ->assertStreamedContent('private-image-bytes');

        $this->actingAs($foreignUser)
            ->get($photo->url())
            ->assertForbidden();
    }

    public function test_a_photo_cannot_be_read_through_a_different_project(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $otherProject = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->for($project)->for($owner, 'user')->create();

        Storage::disk('s3')->put($photo->path, 'private-image-bytes');

        $this->actingAs($owner)
            ->get(route('projects.photos.show', [
                'project' => $otherProject,
                'photo' => $photo,
            ]))
            ->assertNotFound();
    }

    public function test_downloads_are_delivered_as_attachments_and_missing_variants_fail_closed(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $photo = Photo::factory()->generated()->for($project)->for($owner, 'user')->create();

        Storage::disk('s3')->put($photo->path, 'private-image-bytes');

        $this->actingAs($owner)
            ->get($photo->downloadUrl())
            ->assertOk()
            ->assertDownload(basename($photo->path));

        $this->actingAs($owner)
            ->get(route('projects.photos.show', [
                'project' => $project,
                'photo' => $photo,
                'variant' => 'missing',
            ]))
            ->assertNotFound();
    }
}
