<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Billing\OpenBillingPortal;
use App\Actions\Billing\StartSubscriptionCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StartBillingCheckoutRequest;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use App\Services\Billing\BillingPageData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Laravel\Cashier\Checkout;

class BillingController extends Controller
{
    public function __construct(private CurrentAccount $currentAccount) {}

    public function edit(Request $request, BillingPageData $billingPage): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $account = $this->accountForBilling($user);

        return view('pages.settings.billing', ['billing' => $billingPage->for($account)]);
    }

    public function checkout(StartBillingCheckoutRequest $request, StartSubscriptionCheckout $checkout): Checkout
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $account = $this->accountForBilling($user);

        return $checkout->handle(
            $account,
            $user,
            (string) $request->validated('interval'),
            route('billing.edit', ['billing' => 'pending']),
            route('billing.edit', ['billing' => 'canceled']),
        );
    }

    public function portal(Request $request, OpenBillingPortal $portal): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $account = $this->accountForBilling($user);

        return $portal->handle($account, $user, route('billing.edit'));
    }

    private function accountForBilling(User $user): Account
    {
        $account = $this->currentAccount->resolve($user);
        Gate::authorize('manageBilling', $account);

        return $account;
    }
}
