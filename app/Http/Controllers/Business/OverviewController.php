<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BusinessHealth;
use App\Services\RoadmapPlanReader;
use App\Services\RoadmapPlanSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OverviewController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
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

        return view('pages.business.overview', [
            'business' => $business,
            'setupScore' => $health->setupScore($business),
            'riskFlags' => $health->riskFlags($business),
            'nextActions' => $roadmap->nextActions($plan, 3),
            'roadmapTemplates' => $roadmap->currentTemplate($business),
        ]);
    }
}
