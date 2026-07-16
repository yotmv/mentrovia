<?php

namespace App\Http\Controllers;

use App\Enums\BusinessOnboardingTrack;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BusinessHealth;
use App\Services\RoadmapPlanReader;
use App\Services\RoadmapPlanSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function welcome(Request $request, CurrentAccount $currentAccount): View|RedirectResponse
    {
        if ($currentAccount->account()->business !== null) {
            return to_route('dashboard');
        }

        return view('pages.onboarding.welcome');
    }

    public function planReady(
        Request $request,
        BusinessHealth $health,
        RoadmapPlanSynchronizer $synchronizer,
        RoadmapPlanReader $roadmap,
        CurrentAccount $currentAccount,
    ): View|RedirectResponse {
        $business = $currentAccount->account()->business;

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $plan = $business->roadmapPlan ?? $synchronizer->syncForMember($business, $user);
        $finalizedTrack = BusinessOnboardingTrack::tryFrom((string) $request->session()->get('onboarding.finalized_track'));

        return view('pages.onboarding.plan-ready', [
            'business' => $business,
            'setupScore' => $health->setupScore($business),
            'riskFlags' => $health->riskFlags($business),
            'nextActions' => $roadmap->nextActions($plan, 3),
            'roadmapTemplates' => $roadmap->currentTemplate($business),
            'firstTask' => $business->tasks()->active()->whereNull('completed_at')->orderBy('due_on')->orderBy('title')->first(),
            'finalizedTrack' => $finalizedTrack,
        ]);
    }

    public function notSupported(): View
    {
        return view('pages.business.not-supported');
    }
}
