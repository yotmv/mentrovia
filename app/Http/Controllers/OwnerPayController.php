<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeArticle;
use App\Services\OwnerPayGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnerPayController extends Controller
{
    public function __invoke(Request $request, OwnerPayGuide $guide): View|RedirectResponse
    {
        $business = $request->user()->business;

        if ($business === null) {
            return redirect()->route('business.intake');
        }

        $advice = $guide->advise($business);

        return view('pages.owner-pay', [
            'business' => $business,
            'advice' => $advice,
            'articles' => KnowledgeArticle::query()
                ->whereIn('slug', $advice->articleSlugs)
                ->orderBy('title')
                ->get(),
        ]);
    }
}
