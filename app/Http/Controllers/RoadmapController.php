<?php

namespace App\Http\Controllers;

use App\Enums\RoadmapPhase;
use App\Services\RoadmapBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RoadmapController extends Controller
{
    public function __invoke(Request $request, RoadmapBuilder $roadmap): View|RedirectResponse
    {
        $business = $request->user()->business;

        if ($business === null) {
            return redirect()->route('business.intake');
        }

        return view('pages.roadmap', [
            'business' => $business,
            'groupedItems' => $roadmap->buildGrouped($business),
            'phases' => RoadmapPhase::cases(),
        ]);
    }
}
