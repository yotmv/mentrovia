<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeArticle;
use App\Services\Accounts\CurrentAccount;
use App\Services\OwnerPayGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OwnerPayController extends Controller
{
    public function __invoke(Request $request, OwnerPayGuide $guide, CurrentAccount $currentAccount): View|RedirectResponse
    {
        $business = $currentAccount->account()->business;

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        $advice = $guide->advise($business);

        return view('pages.owner-pay', [
            'business' => $business,
            'advice' => $advice,
            'articles' => KnowledgeArticle::query()
                ->published()
                ->whereIn('slug', $advice->articleSlugs)
                ->orderBy('title')
                ->get(),
        ]);
    }
}
