<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class IntakeController extends Controller
{
    public function __invoke(CurrentAccount $currentAccount): View|RedirectResponse
    {
        if ($currentAccount->account()->business()->exists()) {
            return to_route('business.edit');
        }

        return view('pages.business.intake');
    }
}
