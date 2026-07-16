<?php

namespace App\Services\Billing;

use App\Models\Account;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Checkout;
use Stripe\Checkout\Session;

class StripeCheckoutGateway
{
    public function createSubscriptionCheckout(
        Account $account,
        string $token,
        string $subscriptionType,
        string $priceId,
        string $successUrl,
        string $cancelUrl,
    ): Checkout {
        $customer = $account->createOrGetStripeCustomer([], [
            'idempotency_key' => 'billing-customer-'.$account->id.'-'.$token,
        ]);
        $session = Cashier::stripe()->checkout->sessions->create([
            'customer' => $customer->id,
            'client_reference_id' => (string) $account->id,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'metadata' => [
                'account_id' => (string) $account->id,
                'billing_checkout_token' => $token,
            ],
            'mode' => Session::MODE_SUBSCRIPTION,
            'subscription_data' => [
                'metadata' => [
                    'name' => $subscriptionType,
                    'type' => $subscriptionType,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], [
            'idempotency_key' => 'billing-subscription-'.$account->id.'-'.$token,
        ]);

        return new Checkout($account, $session);
    }

    public function retrieve(Account $account, string $sessionId): Checkout
    {
        return new Checkout(
            $account,
            Cashier::stripe()->checkout->sessions->retrieve($sessionId),
        );
    }

    public function expire(string $sessionId): void
    {
        Cashier::stripe()->checkout->sessions->expire($sessionId);
    }
}
