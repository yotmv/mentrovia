<?php

namespace App\Http\Controllers;

use App\Services\BusinessHealth;
use App\Services\RoadmapBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, BusinessHealth $health, RoadmapBuilder $roadmap): View
    {
        $business = $request->user()->business;

        return view('pages.dashboard', [
            'business' => $business,
            'setupScore' => $business === null ? 0 : $health->setupScore($business),
            'riskFlags' => $business === null ? [] : $health->riskFlags($business),
            'missingSetupItems' => $business === null ? [] : $health->missingSetupItems($business),
            'nextActions' => $business === null ? collect() : $roadmap->nextActions($business),
            'upcomingTasks' => $business === null ? collect() : $business->tasks()
                ->whereNull('completed_at')
                ->whereDate('due_on', '>=', now()->toDateString())
                ->orderBy('due_on')
                ->limit(5)
                ->get(),
        ]);
    }
}
