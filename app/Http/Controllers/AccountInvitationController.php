<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\AcceptAccountInvitation;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountInvitationController extends Controller
{
    public function show(Request $request, AccountInvitation $accountInvitation): RedirectResponse|View
    {
        Gate::authorize('accept', $accountInvitation);
        $this->ensureAvailable($request, $accountInvitation);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if (! $user->hasVerifiedEmail()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return to_route('verification.notice');
        }

        return view('pages.settings.account-invitation', [
            'invitation' => $accountInvitation->load('account', 'inviter'),
            'acceptUrl' => $request->fullUrl(),
        ]);
    }

    public function store(
        Request $request,
        AccountInvitation $accountInvitation,
        AcceptAccountInvitation $acceptAccountInvitation,
    ): RedirectResponse {
        Gate::authorize('accept', $accountInvitation);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $acceptAccountInvitation->handle(
            $accountInvitation,
            $user,
            $request->string('token')->toString(),
        );

        $request->session()->regenerate();

        return to_route('dashboard')->with('status', __('Workspace invitation accepted.'));
    }

    private function ensureAvailable(Request $request, AccountInvitation $invitation): void
    {
        abort_unless($invitation->tokenMatches($request->string('token')->toString()), 403);
        abort_unless($invitation->isPending(), 410, __('This invitation is no longer available.'));
    }
}
