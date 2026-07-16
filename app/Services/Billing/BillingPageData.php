<?php

namespace App\Services\Billing;

use App\Enums\AccountEntitlementStatus;
use App\Models\Account;
use App\Models\AccountEntitlement;
use Laravel\Cashier\Subscription;

class BillingPageData
{
    public function __construct(private BillingPriceCatalog $prices) {}

    /**
     * @return array{
     *     workspace_name: string,
     *     plan_label: string,
     *     is_beta: bool,
     *     entitlement_status: string,
     *     entitlement_label: string,
     *     entitlement_color: string,
     *     entitlement_description: string,
     *     entitlement_trial_ends: string|null,
     *     subscription: array{label: string, color: string, detail: string|null}|null,
     *     show_portal: bool,
     *     checkout_pending: bool,
     *     checkout_pending_description: string|null,
     *     checkout_intervals: list<array{value: string, label: string, description: string}>,
     *     checkout_unavailable: bool,
     *     billing_profile_inconsistent: bool
     * }
     */
    public function for(Account $account): array
    {
        $entitlement = $account->entitlement()->firstOrFail();
        $subscriptions = Subscription::query()
            ->where('account_id', $account->id)
            ->where('type', (string) config('billing.subscription_type', 'default'))
            ->latest('id')
            ->get();
        $currentSubscription = $subscriptions->first(
            fn (Subscription $subscription): bool => ! $subscription->ended(),
        );
        $subscription = $currentSubscription ?? $subscriptions->first();
        $status = $entitlement->status;
        $isBeta = $entitlement->plan === 'beta';
        $hasStripeProfile = $account->hasStripeId();
        $checkoutTerminal = is_string($account->billing_checkout_token)
            && $account->billing_checkout_status === 'complete';
        $checkoutPending = $checkoutTerminal || (
            is_string($account->billing_checkout_token)
            && in_array($account->billing_checkout_status, ['preparing', 'open'], true)
            && $account->billing_checkout_expires_at?->isFuture() === true
        );
        $checkoutRetryable = is_string($account->billing_checkout_token)
            && in_array($account->billing_checkout_status, ['preparing', 'open'], true)
            && $account->billing_checkout_expires_at?->isFuture() !== true;
        $billingProfileInconsistent = ($subscription instanceof Subscription && ! $hasStripeProfile)
            || (! $isBeta
                && $status === AccountEntitlementStatus::Active
                && (! $hasStripeProfile || ! $currentSubscription instanceof Subscription));
        $mayStartCheckout = ! $isBeta
            && in_array($status, [AccountEntitlementStatus::Trialing, AccountEntitlementStatus::Suspended, AccountEntitlementStatus::Canceled], true)
            && ! $currentSubscription instanceof Subscription
            && ! $checkoutPending
            && ! $billingProfileInconsistent;
        $retryIntervalIsValid = ! $checkoutRetryable
            || in_array($account->billing_checkout_interval, ['monthly', 'yearly'], true);
        $intervals = $mayStartCheckout && $retryIntervalIsValid
            ? $this->checkoutIntervals($checkoutRetryable ? $account->billing_checkout_interval : null)
            : [];

        return [
            'workspace_name' => $account->name,
            'plan_label' => $isBeta ? __('Grandfathered beta') : __('Standard'),
            'is_beta' => $isBeta,
            'entitlement_status' => $status->value,
            'entitlement_label' => $this->entitlementLabel($status),
            'entitlement_color' => $this->entitlementColor($status),
            'entitlement_description' => $this->entitlementDescription($status, $isBeta),
            'entitlement_trial_ends' => $this->trialEnds($entitlement),
            'subscription' => $this->subscriptionState($subscription),
            'show_portal' => $hasStripeProfile,
            'checkout_pending' => $checkoutPending,
            'checkout_pending_description' => $checkoutPending
                ? $this->checkoutPendingDescription($checkoutTerminal)
                : null,
            'checkout_intervals' => $intervals,
            'checkout_unavailable' => $mayStartCheckout && $intervals === [],
            'billing_profile_inconsistent' => $billingProfileInconsistent,
        ];
    }

    /** @return list<array{value: string, label: string, description: string}> */
    private function checkoutIntervals(?string $onlyInterval = null): array
    {
        $configuredIntervals = $this->prices->configuredIntervals();

        if (in_array($onlyInterval, ['monthly', 'yearly'], true)) {
            $configuredIntervals = array_values(array_filter(
                $configuredIntervals,
                fn (string $interval): bool => $interval === $onlyInterval,
            ));
        }

        return array_map(fn (string $interval): array => [
            'value' => $interval,
            'label' => $interval === 'monthly' ? __('Monthly') : __('Yearly'),
            'description' => $interval === 'monthly'
                ? __('Pay month to month with the flexibility to change later.')
                : __('Pay once a year and manage renewal through Stripe.'),
        ], $configuredIntervals);
    }

    private function checkoutPendingDescription(bool $terminal): string
    {
        return $terminal
            ? __('Stripe reports that checkout completed. A signed subscription update must confirm billing before another checkout can start.')
            : __('A Stripe checkout is still open or being prepared. Finish it or wait for it to expire before starting another subscription.');
    }

    private function entitlementLabel(AccountEntitlementStatus $status): string
    {
        return match ($status) {
            AccountEntitlementStatus::Active => __('Active'),
            AccountEntitlementStatus::Trialing => __('Trialing'),
            AccountEntitlementStatus::Suspended => __('Suspended'),
            AccountEntitlementStatus::Canceled => __('Canceled'),
        };
    }

    private function entitlementColor(AccountEntitlementStatus $status): string
    {
        return match ($status) {
            AccountEntitlementStatus::Active => 'green',
            AccountEntitlementStatus::Trialing => 'blue',
            AccountEntitlementStatus::Suspended => 'amber',
            AccountEntitlementStatus::Canceled => 'red',
        };
    }

    private function entitlementDescription(AccountEntitlementStatus $status, bool $isBeta): string
    {
        if ($isBeta && $status === AccountEntitlementStatus::Active) {
            return __('This workspace keeps its full beta access. No subscription is required.');
        }

        return match ($status) {
            AccountEntitlementStatus::Active => __('Your workspace has full access under the Standard plan.'),
            AccountEntitlementStatus::Trialing => __('Your workspace has full access during its Standard trial.'),
            AccountEntitlementStatus::Suspended => __('Workspace access is paused until billing is resolved.'),
            AccountEntitlementStatus::Canceled => __('The Standard subscription is canceled and paid access has ended.'),
        };
    }

    private function trialEnds(AccountEntitlement $entitlement): ?string
    {
        if ($entitlement->status !== AccountEntitlementStatus::Trialing) {
            return null;
        }

        return $entitlement->trial_ends_at?->toFormattedDateString();
    }

    /** @return array{label: string, color: string, detail: string|null}|null */
    private function subscriptionState(?Subscription $subscription): ?array
    {
        if (! $subscription instanceof Subscription) {
            return null;
        }

        return [
            'label' => $this->subscriptionLabel($subscription->stripe_status),
            'color' => $this->subscriptionColor($subscription->stripe_status),
            'detail' => $this->subscriptionDetail($subscription),
        ];
    }

    private function subscriptionLabel(string $status): string
    {
        return match ($status) {
            'active' => __('Active'),
            'trialing' => __('Trialing'),
            'past_due' => __('Past due'),
            'unpaid' => __('Unpaid'),
            'incomplete' => __('Pending activation'),
            'incomplete_expired' => __('Activation expired'),
            'paused' => __('Paused'),
            'canceled' => __('Canceled'),
            default => __('Updating'),
        };
    }

    private function subscriptionColor(string $status): string
    {
        return match ($status) {
            'active' => 'green',
            'trialing' => 'blue',
            'past_due', 'incomplete', 'paused' => 'amber',
            'unpaid', 'incomplete_expired', 'canceled' => 'red',
            default => 'zinc',
        };
    }

    private function subscriptionDetail(Subscription $subscription): ?string
    {
        return match (true) {
            $subscription->ends_at?->isFuture() === true => $this->translate('Access is scheduled to end on :date.', [
                'date' => $subscription->ends_at->toFormattedDateString(),
            ]),
            $subscription->trial_ends_at?->isFuture() === true => $this->translate('Stripe trial ends on :date.', [
                'date' => $subscription->trial_ends_at->toFormattedDateString(),
            ]),
            default => null,
        };
    }

    /** @param array<string, string> $replace */
    private function translate(string $key, array $replace): string
    {
        $translation = __($key, $replace);

        return is_string($translation) ? $translation : $key;
    }
}
