<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('pages.projects.index');
    }

    public function show(Project $project): View
    {
        Gate::authorize('view', $project);

        return view('pages.projects.show', ['project' => $project]);
    }
}
