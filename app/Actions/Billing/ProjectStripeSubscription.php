<?php

namespace App\Actions\Billing;

use App\Enums\AccountEntitlementStatus;
use App\Models\Account;
use App\Models\AccountEntitlement;
use App\Models\StripeWebhookProjection;
use App\Services\Billing\BillingPriceCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Subscription;

class ProjectStripeSubscription
{
    public function __construct(private BillingPriceCatalog $prices) {}

    public function admit(
        string $eventId,
        string $eventType,
        int $stripeCreatedAt,
        ?int $accountId,
        ?string $subscriptionStatus = null,
    ): bool {
        return DB::transaction(function () use ($eventId, $eventType, $stripeCreatedAt, $accountId, $subscriptionStatus): bool {
            $existing = StripeWebhookProjection::query()
                ->where('stripe_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof StripeWebhookProjection) {
                if ($existing->outcome !== 'processing') {
                    return false;
                }

                if (is_int($existing->account_id)
                    && $this->hasNewerProjection($existing->account_id, $stripeCreatedAt, $eventId)) {
                    $existing->update(['outcome' => 'stale_ignored', 'processed_at' => now()]);

                    return false;
                }

                return true;
            }

            if ($accountId === null) {
                $this->record($eventId, $eventType, $subscriptionStatus, $stripeCreatedAt, null, 'account_missing');

                return false;
            }

            if (Account::query()->whereKey($accountId)->lockForUpdate()->doesntExist()) {
                $this->record($eventId, $eventType, $subscriptionStatus, $stripeCreatedAt, $accountId, 'account_deleted');

                return false;
            }

            if ($this->hasNewerProjection($accountId, $stripeCreatedAt, $eventId)) {
                $this->record($eventId, $eventType, $subscriptionStatus, $stripeCreatedAt, $accountId, 'stale_ignored');

                return false;
            }

            $this->record($eventId, $eventType, $subscriptionStatus, $stripeCreatedAt, $accountId, 'processing');

            return true;
        }, attempts: 3);
    }

    public function complete(string $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $projection = StripeWebhookProjection::query()
                ->where('stripe_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (! $projection instanceof StripeWebhookProjection || $projection->outcome !== 'processing') {
                return;
            }

            $account = is_int($projection->account_id)
                ? Account::query()->lockForUpdate()->find($projection->account_id)
                : null;

            if (! $account instanceof Account) {
                $projection->update(['outcome' => 'account_deleted', 'processed_at' => now()]);

                return;
            }

            $entitlement = AccountEntitlement::query()
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            if (! $entitlement instanceof AccountEntitlement) {
                $projection->update(['outcome' => 'entitlement_missing', 'processed_at' => now()]);

                return;
            }

            if ($this->hasNewerProjection(
                $account->id,
                $projection->stripe_created_at,
                $projection->stripe_event_id,
            )) {
                $projection->update(['outcome' => 'stale_ignored', 'processed_at' => now()]);

                return;
            }

            $subscriptions = Subscription::query()
                ->with('items')
                ->where('account_id', $account->id)
                ->where('type', (string) config('billing.subscription_type', 'default'))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $outcome = $this->project(
                $entitlement,
                $subscriptions,
                $projection->event_type,
                $projection->subscription_status,
            );
            $account->forceFill([
                'billing_checkout_token' => null,
                'billing_checkout_session_id' => null,
                'billing_checkout_expires_at' => null,
                'billing_checkout_status' => null,
                'billing_checkout_interval' => null,
                'billing_checkout_price_fingerprint' => null,
            ])->save();
            $projection->update(['outcome' => $outcome, 'processed_at' => now()]);
        }, attempts: 3);
    }

    private function hasNewerProjection(int $accountId, int $stripeCreatedAt, string $eventId): bool
    {
        return StripeWebhookProjection::query()
            ->where('account_id', $accountId)
            ->where('stripe_event_id', '!=', $eventId)
            ->where(function ($query) use ($stripeCreatedAt, $eventId): void {
                $query->where('stripe_created_at', '>', $stripeCreatedAt)
                    ->orWhere(function ($query) use ($stripeCreatedAt, $eventId): void {
                        $query->where('stripe_created_at', $stripeCreatedAt)
                            ->where('stripe_event_id', '>', $eventId);
                    });
            })
            ->lockForUpdate()
            ->exists();
    }

    /** @param Collection<int, Subscription> $subscriptions */
    private function project(
        AccountEntitlement $entitlement,
        Collection $subscriptions,
        string $eventType,
        ?string $subscriptionStatus,
    ): string {
        if ($eventType === 'customer.deleted') {
            return $this->cancelStandard($entitlement, 'customer_deleted');
        }

        $current = $subscriptions->filter(fn (Subscription $subscription): bool => ! $subscription->ended());

        if ($current->count() > 1) {
            return $this->suspend($entitlement, 'multiple_subscriptions');
        }

        $subscription = $current->first();

        if (! $subscription instanceof Subscription) {
            if ($entitlement->plan === 'beta') {
                return 'beta_unchanged';
            }

            if ($eventType === 'customer.subscription.deleted') {
                return $this->cancelStandard($entitlement, 'subscription_ended');
            }

            if ($subscriptionStatus === 'incomplete_expired') {
                return $this->suspend($entitlement, 'subscription_incomplete_expired');
            }

            return $this->suspend($entitlement, 'subscription_missing');
        }

        $priceIds = $subscription->items
            ->pluck('stripe_price')
            ->filter(fn (mixed $price): bool => is_string($price) && $price !== '')
            ->unique()
            ->values();

        if ($priceIds->isEmpty()
            && is_string($subscription->stripe_price)
            && $subscription->stripe_price !== '') {
            $priceIds->push($subscription->stripe_price);
        }

        if ($priceIds->count() !== 1 || $this->prices->planForPrice((string) $priceIds->first()) !== 'standard') {
            return $this->suspend($entitlement, 'price_unrecognized');
        }

        if ($subscription->stripe_status === 'active') {
            $entitlement->update([
                'plan' => 'standard',
                'status' => AccountEntitlementStatus::Active,
                'trial_ends_at' => null,
            ]);

            return 'standard_active';
        }

        if ($subscription->stripe_status === 'trialing' && $subscription->trial_ends_at?->isFuture() === true) {
            $entitlement->update([
                'plan' => 'standard',
                'status' => AccountEntitlementStatus::Trialing,
                'trial_ends_at' => $subscription->trial_ends_at,
            ]);

            return 'standard_trialing';
        }

        return $this->suspend($entitlement, 'subscription_inactive');
    }

    private function suspend(AccountEntitlement $entitlement, string $outcome): string
    {
        $entitlement->update([
            'plan' => 'standard',
            'status' => AccountEntitlementStatus::Suspended,
            'trial_ends_at' => null,
        ]);

        return $outcome;
    }

    private function cancelStandard(AccountEntitlement $entitlement, string $outcome): string
    {
        if ($entitlement->plan === 'standard') {
            $entitlement->update([
                'status' => AccountEntitlementStatus::Canceled,
                'trial_ends_at' => null,
            ]);
        }

        return $entitlement->plan === 'beta' ? 'beta_unchanged' : $outcome;
    }

    private function record(
        string $eventId,
        string $eventType,
        ?string $subscriptionStatus,
        int $stripeCreatedAt,
        ?int $accountId,
        string $outcome,
    ): void {
        StripeWebhookProjection::query()->create([
            'stripe_event_id' => $eventId,
            'account_id' => $accountId,
            'event_type' => $eventType,
            'subscription_status' => $subscriptionStatus,
            'stripe_created_at' => $stripeCreatedAt,
            'outcome' => $outcome,
            'processed_at' => $outcome === 'processing' ? null : now(),
        ]);
    }
}
