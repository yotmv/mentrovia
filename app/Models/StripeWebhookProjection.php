<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $stripe_event_id
 * @property int|null $account_id
 * @property string $event_type
 * @property string|null $subscription_status
 * @property int $stripe_created_at
 * @property string $outcome
 * @property Carbon|null $processed_at
 */
#[Fillable(['stripe_event_id', 'account_id', 'event_type', 'subscription_status', 'stripe_created_at', 'outcome', 'processed_at'])]
class StripeWebhookProjection extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'stripe_created_at' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
