<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private CurrentAccount $currentAccount) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $account = $this->currentAccount->resolve($user);
        Gate::authorize('view', $account);

        return view('pages.settings.account');
    }
}
