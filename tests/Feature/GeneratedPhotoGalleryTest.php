<?php

namespace Tests\Feature;

use App\Enums\PhotoProcessingStatus;
use App\Enums\ProjectPermission;
use App\Models\Photo;
use App\Models\Project;
use App\Models\User;
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

    public function test_download_urls_resolve_for_variants_and_the_original(): void
    {
        $photo = Photo::factory()->generated()->withDerivatives()->create();

        $this->assertStringContainsString('original.png', $photo->downloadUrl());
        $this->assertStringContainsString('hero.webp', $photo->downloadUrl('hero'));
    }
}
