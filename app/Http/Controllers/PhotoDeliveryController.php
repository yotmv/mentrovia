<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoDeliveryController extends Controller
{
    public function __invoke(Request $request, Project $project, Photo $photo): StreamedResponse
    {
        Gate::authorize('view', $project);

        abort_unless($photo->project_id === $project->id, 404);

        $variant = $request->string('variant')->toString();
        $path = $variant === '' ? $photo->path : $photo->derivativePath($variant);

        abort_if($path === null, 404);

        $disk = Storage::disk($photo->disk);

        abort_unless($disk->exists($path), 404);

        return $disk->response(
            $path,
            basename($path),
            ['Cache-Control' => 'private, no-store'],
            $request->boolean('download') ? 'attachment' : 'inline',
        );
    }
}
