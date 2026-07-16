<?php

namespace App\Services\Billing;

use InvalidArgumentException;
use RuntimeException;

class BillingPriceCatalog
{
    /** @return list<string> */
    public function configuredIntervals(): array
    {
        return array_values(array_filter(
            ['monthly', 'yearly'],
            fn (string $interval): bool => is_string(config("billing.plans.standard.prices.{$interval}"))
                && trim((string) config("billing.plans.standard.prices.{$interval}")) !== '',
        ));
    }

    public function priceForInterval(string $interval): string
    {
        if (! in_array($interval, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Unsupported billing interval.');
        }

        $price = config("billing.plans.standard.prices.{$interval}");

        if (! is_string($price) || trim($price) === '') {
            throw new RuntimeException('The selected billing interval is not configured.');
        }

        return trim($price);
    }

    public function planForPrice(string $priceId): ?string
    {
        foreach (['monthly', 'yearly'] as $interval) {
            $configured = config("billing.plans.standard.prices.{$interval}");

            if (is_string($configured) && $configured !== '' && hash_equals($configured, $priceId)) {
                return 'standard';
            }
        }

        return null;
    }
}
