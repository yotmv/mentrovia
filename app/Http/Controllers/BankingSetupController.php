<?php

namespace App\Http\Controllers;

use App\Actions\Business\UpdateBankingChecklistItem;
use App\Http\Requests\UpdateBankingChecklistItemRequest;
use App\Models\KnowledgeArticle;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\BankingSetupGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BankingSetupController extends Controller
{
    public function __invoke(Request $request, BankingSetupGuide $guide, CurrentAccount $currentAccount): View|RedirectResponse
    {
        $business = $currentAccount->account()->business()->with('profileAnswers')->first();

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        $advice = $guide->advise($business);

        return view('pages.banking-setup', [
            'business' => $business,
            'advice' => $advice,
            'articles' => KnowledgeArticle::query()
                ->published()
                ->whereIn('slug', $advice->articleSlugs)
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function update(UpdateBankingChecklistItemRequest $request, string $key, CurrentAccount $currentAccount, UpdateBankingChecklistItem $updateChecklist): RedirectResponse
    {
        $business = $currentAccount->account()->business;

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $updateChecklist->handle($business, $user, $key, $request->boolean('completed'));

        return redirect()->route('banking-setup');
    }
}
