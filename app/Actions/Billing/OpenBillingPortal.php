<?php

namespace App\Actions\Billing;

use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenBillingPortal
{
    public function __construct(private AccountMutationGate $accountMutationGate) {}

    public function handle(Account $account, User $actor, string $returnUrl): RedirectResponse
    {
        $lockedAccount = DB::transaction(function () use ($account, $actor): Account {
            $locked = $this->accountMutationGate->lockOwnerOrFail($account->id, $actor->id);

            if (! $locked->hasStripeId()) {
                throw ValidationException::withMessages([
                    'billing' => __('No Stripe billing profile exists for this workspace.'),
                ]);
            }

            return $locked;
        }, attempts: 3);

        return $lockedAccount->redirectToBillingPortal($returnUrl);
    }
}
