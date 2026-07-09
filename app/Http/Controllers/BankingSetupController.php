<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBankingChecklistItemRequest;
use App\Models\KnowledgeArticle;
use App\Services\BankingSetupGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankingSetupController extends Controller
{
    public function __invoke(Request $request, BankingSetupGuide $guide): View|RedirectResponse
    {
        $business = $request->user()->business()->with('profileAnswers')->first();

        if ($business === null) {
            return redirect()->route('business.intake');
        }

        $advice = $guide->advise($business);

        return view('pages.banking-setup', [
            'business' => $business,
            'advice' => $advice,
            'articles' => KnowledgeArticle::query()
                ->whereIn('slug', $advice->articleSlugs)
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function update(UpdateBankingChecklistItemRequest $request, string $key, BankingSetupGuide $guide): RedirectResponse
    {
        $business = $request->user()->business;

        if ($business === null) {
            return redirect()->route('business.intake');
        }

        $guide->markCompleted($business, $key, $request->boolean('completed'));

        return redirect()->route('banking-setup');
    }
}
