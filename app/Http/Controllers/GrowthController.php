<?php

namespace App\Http\Controllers;

use App\Services\Accounts\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GrowthController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, CurrentAccount $currentAccount): View|RedirectResponse
    {
        $account = $currentAccount->account();
        $business = $account->business;

        if ($business === null) {
            return to_route('onboarding.welcome');
        }

        return view('pages.growth', [
            'business' => $business,
            'brandKit' => $business->brandKits()->orderByDesc('version')->first(),
            'advertisingKit' => $business->advertisingKits()->orderByDesc('version')->first(),
            'projects' => $account->projects()->withCount('photos')->orderByDesc('project_date')->limit(3)->get(),
        ]);
    }
}
