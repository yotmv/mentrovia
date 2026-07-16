<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\RoadmapPlanSynchronizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoadmapController extends Controller
{
    public function __invoke(Request $request, RoadmapPlanSynchronizer $synchronizer, CurrentAccount $currentAccount): View|RedirectResponse
    {
        $business = $currentAccount->account()->business;

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($business->roadmapPlan === null) {
            $synchronizer->syncForMember($business, $user);
        }

        return view('pages.roadmap', [
            'business' => $business,
        ]);
    }
}
