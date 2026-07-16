<?php

namespace App\Services\Billing;

use Laravel\Cashier\Cashier;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;

class StripeCustomerTeardown
{
    public function delete(string $stripeCustomerId): string
    {
        try {
            $deleted = Cashier::stripe()->customers->delete($stripeCustomerId);
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() === 'resource_missing'
                && in_array($exception->getStripeParam(), [null, 'id'], true)) {
                return $this->proof($stripeCustomerId);
            }

            throw $exception;
        }

        if ($deleted->id !== $stripeCustomerId || ! $deleted->isDeleted()) {
            throw new RuntimeException('Stripe did not confirm customer deletion.');
        }

        return $this->proof($stripeCustomerId);
    }

    public function missingCustomerProof(): string
    {
        return $this->proof('no_stripe_customer');
    }

    private function proof(string $stripeCustomerId): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('The application key is required for billing teardown proofs.');
        }

        return hash_hmac('sha256', 'stripe_customer_deleted|'.$stripeCustomerId, $key);
    }
}
