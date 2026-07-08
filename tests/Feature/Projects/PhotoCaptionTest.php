<?php

use App\Enums\PhotoTextSource;
use App\Jobs\DescribeUploadedPhoto;
use App\Jobs\GeneratePhotoDerivatives;
use App\Livewire\Projects\Show;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('s3');
    config(['photostudio.disk' => 's3']);
});

test('uploads with user text store the text with a user source and skip auto-captioning', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(Show::class, ['project' => $project])
        ->set('uploads', [UploadedFile::fake()->image('storefront.jpg')])
        ->set('uploadDescription', 'Front window, before cleaning')
        ->call('saveUploads')
        ->assertHasNoErrors();

    $photo = $project->uploadedPhotos()->sole();

    expect($photo->text)->toBe('Front window, before cleaning')
        ->and($photo->text_source)->toBe(PhotoTextSource::User);

    Queue::assertNotPushed(DescribeUploadedPhoto::class);
});

test('uploads without text are queued for auto-captioning after derivatives', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner, 'owner')->create();
    $this->actingAs($owner);

    Livewire::test(Show::class, ['project' => $project])
        ->set('uploads', [UploadedFile::fake()->image('storefront.jpg')])
        ->call('saveUploads')
        ->assertHasNoErrors();

    $photo = $project->uploadedPhotos()->sole();

    expect($photo->text)->toBeNull()
        ->and($photo->text_source)->toBeNull();

    // The derivatives job chains DescribeUploadedPhoto once the normalized
    // LLM input exists; the upload action itself only queues derivatives.
    Queue::assertPushed(GeneratePhotoDerivatives::class, 1);
});
