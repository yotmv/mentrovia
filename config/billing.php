<?php

return [
    'subscription_type' => 'default',
    'trial_days' => 14,
    'checkout_reservation_minutes' => 30,
    'webhook_processing_budget_seconds' => 300,
    'webhook_lock_seconds' => 600,
    'webhook_lock_wait_seconds' => 30,

    'plans' => [
        'standard' => [
            'prices' => [
                'monthly' => env('STRIPE_STANDARD_MONTHLY_PRICE'),
                'yearly' => env('STRIPE_STANDARD_YEARLY_PRICE'),
            ],
        ],
    ],
];
