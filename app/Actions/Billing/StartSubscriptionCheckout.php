<?php

namespace App\Actions\Billing;

use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Billing\BillingPriceCatalog;
use App\Services\Billing\StripeCheckoutGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Checkout;
use Stripe\Checkout\Session;
use Throwable;

class StartSubscriptionCheckout
{
    public function __construct(
        private AccountMutationGate $accountMutationGate,
        private BillingPriceCatalog $prices,
        private StripeCheckoutGateway $stripe,
    ) {}

    public function handle(Account $account, User $actor, string $interval, string $successUrl, string $cancelUrl): Checkout
    {
        $priceId = $this->prices->priceForInterval($interval);
        $priceFingerprint = hash('sha256', implode('|', [
            (string) config('billing.subscription_type', 'default'),
            $interval,
            $priceId,
        ]));
        $reservation = $this->reserve($account, $actor, $interval, $priceFingerprint);

        if (is_string($reservation['session_id'])) {
            return $this->reuseCheckout($reservation['account'], $reservation['token'], $reservation['session_id']);
        }

        try {
            $checkout = $this->stripe->createSubscriptionCheckout(
                $reservation['account'],
                $reservation['token'],
                (string) config('billing.subscription_type', 'default'),
                $priceId,
                $successUrl,
                $cancelUrl,
            );
        } catch (Throwable $exception) {
            $this->makeReservationRetryable($reservation['account']->id, $reservation['token']);

            throw $exception;
        }

        $session = $checkout->asStripeCheckoutSession();

        if ($session->status !== Session::STATUS_OPEN) {
            if ($session->status === Session::STATUS_EXPIRED) {
                $this->clearReservation($reservation['account']->id, $reservation['token']);
            } elseif ($session->status === Session::STATUS_COMPLETE) {
                $this->markReservationComplete(
                    $reservation['account']->id,
                    $reservation['token'],
                    $session->id,
                );
            } else {
                $this->makeReservationRetryable($reservation['account']->id, $reservation['token']);
            }

            throw ValidationException::withMessages([
                'interval' => __('This checkout is no longer open. Please wait for billing to finish, then try again.'),
            ]);
        }

        if ($session->id === '' || ! is_string($session->url) || $session->url === '') {
            $this->makeReservationRetryable($reservation['account']->id, $reservation['token']);

            throw new \RuntimeException('Stripe did not return a usable checkout session.');
        }

        $mayRedirect = DB::transaction(function () use ($reservation, $session): bool {
            $locked = Account::query()->lockForUpdate()->find($reservation['account']->id);

            if (! $locked instanceof Account
                || ! is_string($locked->billing_checkout_token)
                || ! hash_equals($locked->billing_checkout_token, $reservation['token'])
                || $locked->erasure_started_at !== null) {
                return false;
            }

            $locked->forceFill([
                'billing_checkout_session_id' => $session->id,
                'billing_checkout_expires_at' => Carbon::createFromTimestamp($session->expires_at),
                'billing_checkout_status' => 'open',
            ])->save();

            return true;
        }, attempts: 3);

        if (! $mayRedirect) {
            $this->expireCheckout($session->id);
            $this->clearReservation($reservation['account']->id, $reservation['token']);

            throw ValidationException::withMessages([
                'interval' => __('This workspace can no longer start checkout.'),
            ]);
        }

        return $checkout;
    }

    /** @return array{account: Account, token: string, session_id: string|null} */
    private function reserve(Account $account, User $actor, string $interval, string $priceFingerprint): array
    {
        return DB::transaction(function () use ($account, $actor, $interval, $priceFingerprint): array {
            $locked = $this->accountMutationGate->lockOwnerOrFail($account->id, $actor->id);
            $subscriptionType = (string) config('billing.subscription_type', 'default');
            $hasCurrentSubscription = $locked->subscriptions()
                ->where('type', $subscriptionType)
                ->where(fn ($query) => $query
                    ->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now()))
                ->lockForUpdate()
                ->exists();

            if ($hasCurrentSubscription) {
                throw ValidationException::withMessages([
                    'interval' => __('Manage the existing subscription in the billing portal.'),
                ]);
            }

            if ($locked->billing_checkout_status === 'complete') {
                throw ValidationException::withMessages([
                    'interval' => __('Stripe completed this checkout. Billing confirmation is still pending; please do not start another subscription.'),
                ]);
            }

            $hasReservation = is_string($locked->billing_checkout_token);

            if ($hasReservation
                && (! is_string($locked->billing_checkout_price_fingerprint)
                    || ! hash_equals($locked->billing_checkout_price_fingerprint, $priceFingerprint))) {
                $existingInterval = is_string($locked->billing_checkout_interval)
                    ? $locked->billing_checkout_interval
                    : __('existing');

                throw ValidationException::withMessages([
                    'interval' => __('A :interval checkout is already pending. Finish or expire it before choosing another billing interval.', [
                        'interval' => $existingInterval,
                    ]),
                ]);
            }

            if ($hasReservation && is_string($locked->billing_checkout_session_id)) {
                return [
                    'account' => $locked,
                    'token' => $locked->billing_checkout_token,
                    'session_id' => $locked->billing_checkout_session_id,
                ];
            }

            $reservationActive = $hasReservation
                && $locked->billing_checkout_expires_at?->isFuture() === true;

            if ($reservationActive) {
                throw ValidationException::withMessages([
                    'interval' => __('A checkout session is already being prepared. Please try again shortly.'),
                ]);
            }

            $token = is_string($locked->billing_checkout_token)
                ? $locked->billing_checkout_token
                : (string) Str::uuid();

            $locked->forceFill([
                'billing_checkout_token' => $token,
                'billing_checkout_session_id' => null,
                'billing_checkout_expires_at' => now()->addMinutes($this->reservationMinutes()),
                'billing_checkout_status' => 'preparing',
                'billing_checkout_interval' => $interval,
                'billing_checkout_price_fingerprint' => $priceFingerprint,
            ])->save();

            return ['account' => $locked, 'token' => $token, 'session_id' => null];
        }, attempts: 3);
    }

    private function reuseCheckout(Account $account, string $token, string $sessionId): Checkout
    {
        $checkout = $this->stripe->retrieve($account, $sessionId);
        $session = $checkout->asStripeCheckoutSession();

        if ($session->status !== Session::STATUS_OPEN || ! is_string($session->url) || $session->url === '') {
            if ($session->status === Session::STATUS_EXPIRED) {
                $this->clearReservation($account->id, $token);
            } elseif ($session->status === Session::STATUS_COMPLETE) {
                $this->markReservationComplete($account->id, $token, $sessionId);
            }

            throw ValidationException::withMessages([
                'interval' => __('This checkout is no longer open. Please wait for billing to finish, then try again.'),
            ]);
        }

        $mayRedirect = DB::transaction(function () use ($account, $token, $sessionId): bool {
            $locked = Account::query()->lockForUpdate()->find($account->id);

            return $locked instanceof Account
                && $locked->erasure_started_at === null
                && is_string($locked->billing_checkout_token)
                && hash_equals($locked->billing_checkout_token, $token)
                && is_string($locked->billing_checkout_session_id)
                && hash_equals($locked->billing_checkout_session_id, $sessionId);
        }, attempts: 3);

        if (! $mayRedirect) {
            $this->expireCheckout($sessionId);

            throw ValidationException::withMessages([
                'interval' => __('This workspace can no longer start checkout.'),
            ]);
        }

        return $checkout;
    }

    private function makeReservationRetryable(int $accountId, string $token): void
    {
        DB::transaction(function () use ($accountId, $token): void {
            $locked = Account::query()->lockForUpdate()->find($accountId);

            if ($locked instanceof Account
                && is_string($locked->billing_checkout_token)
                && hash_equals($locked->billing_checkout_token, $token)
                && $locked->billing_checkout_session_id === null) {
                $locked->forceFill(['billing_checkout_expires_at' => now()])->save();
            }
        }, attempts: 3);
    }

    private function clearReservation(int $accountId, string $token): void
    {
        DB::transaction(function () use ($accountId, $token): void {
            $locked = Account::query()->lockForUpdate()->find($accountId);

            if ($locked instanceof Account
                && is_string($locked->billing_checkout_token)
                && hash_equals($locked->billing_checkout_token, $token)) {
                $locked->forceFill([
                    'billing_checkout_token' => null,
                    'billing_checkout_session_id' => null,
                    'billing_checkout_expires_at' => null,
                    'billing_checkout_status' => null,
                    'billing_checkout_interval' => null,
                    'billing_checkout_price_fingerprint' => null,
                ])->save();
            }
        }, attempts: 3);
    }

    private function markReservationComplete(int $accountId, string $token, string $sessionId): void
    {
        DB::transaction(function () use ($accountId, $token, $sessionId): void {
            $locked = Account::query()->lockForUpdate()->find($accountId);

            if ($locked instanceof Account
                && is_string($locked->billing_checkout_token)
                && hash_equals($locked->billing_checkout_token, $token)) {
                $locked->forceFill([
                    'billing_checkout_session_id' => $sessionId,
                    'billing_checkout_expires_at' => null,
                    'billing_checkout_status' => 'complete',
                ])->save();
            }
        }, attempts: 3);
    }

    private function expireCheckout(string $sessionId): void
    {
        try {
            $this->stripe->expire($sessionId);
        } catch (Throwable) {
            // Customer teardown remains the authoritative erasure fence.
        }
    }

    private function reservationMinutes(): int
    {
        return max(1, (int) config('billing.checkout_reservation_minutes', 30));
    }
}
