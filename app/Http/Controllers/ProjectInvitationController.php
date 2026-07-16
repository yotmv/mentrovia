<?php

namespace App\Http\Controllers;

use App\Actions\Projects\AcceptProjectInvitation;
use App\Models\ProjectInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectInvitationController extends Controller
{
    public function show(Request $request, ProjectInvitation $projectInvitation): View
    {
        Gate::authorize('accept', $projectInvitation);

        $this->ensureAvailable($request, $projectInvitation);

        return view('pages.projects.invitation', [
            'invitation' => $projectInvitation->load('project.owner'),
            'acceptUrl' => $request->fullUrl(),
        ]);
    }

    public function store(
        Request $request,
        ProjectInvitation $projectInvitation,
        AcceptProjectInvitation $acceptProjectInvitation,
    ): RedirectResponse {
        Gate::authorize('accept', $projectInvitation);

        $project = $acceptProjectInvitation->handle(
            $projectInvitation,
            $request->user(),
            $request->string('token')->toString(),
        );

        return to_route('projects.show', $project)
            ->with('status', __('Project invitation accepted.'));
    }

    private function ensureAvailable(Request $request, ProjectInvitation $invitation): void
    {
        abort_unless(
            $invitation->tokenMatches($request->string('token')->toString()),
            403,
        );

        abort_unless(
            $invitation->isPending(),
            410,
            __('This invitation is no longer available.'),
        );
    }
}
