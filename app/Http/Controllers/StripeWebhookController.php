<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ProjectStripeSubscription;
use App\Models\Account;
use App\Models\StripeWebhookProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Http\Controllers\WebhookController;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends WebhookController
{
    public function __construct(private ProjectStripeSubscription $projectSubscription)
    {
        $this->middleware(VerifyWebhookSignature::class);
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->json()->all();
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;
        $stripeCreatedAt = $payload['created'] ?? null;

        abort_unless(is_string($eventId) && $eventId !== '', 400);
        abort_unless(is_string($eventType) && $eventType !== '', 400);
        abort_unless(is_int($stripeCreatedAt), 400);

        $accountId = $this->correlatedAccountId($payload, $eventType);
        $subscriptionStatus = data_get($payload, 'data.object.status');
        $subscriptionStatus = is_string($subscriptionStatus) ? $subscriptionStatus : null;

        if (! $this->projectsEntitlement($eventType)) {
            return parent::handleWebhook($request);
        }

        $accountId ??= $this->knownProjectionAccountId($eventId);

        if ($accountId === null) {
            $this->projectSubscription->admit(
                $eventId,
                $eventType,
                $stripeCreatedAt,
                null,
                $subscriptionStatus,
            );

            return new Response('Webhook Handled', 200);
        }

        /** @var Response $response */
        $response = Cache::lock('stripe-webhook-account:'.$accountId, $this->webhookLockSeconds())->block(
            $this->webhookLockWaitSeconds(),
            fn (): Response => $this->handleProjectedWebhook(
                $request,
                $eventId,
                $eventType,
                $stripeCreatedAt,
                $accountId,
                $subscriptionStatus,
            ),
        );

        return $response;
    }

    private function handleProjectedWebhook(
        Request $request,
        string $eventId,
        string $eventType,
        int $stripeCreatedAt,
        int $accountId,
        ?string $subscriptionStatus,
    ): Response {
        if (! $this->projectSubscription->admit(
            $eventId,
            $eventType,
            $stripeCreatedAt,
            $accountId,
            $subscriptionStatus,
        )) {
            return new Response('Webhook Handled', 200);
        }

        $response = parent::handleWebhook($request);

        if ($response->isSuccessful()) {
            $this->projectSubscription->complete($eventId);
        }

        return $response;
    }

    /** @param array<string, mixed> $payload */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        parent::handleCustomerSubscriptionUpdated($payload);

        return $this->successMethod();
    }

    /** @param array<string, mixed> $payload */
    private function correlatedAccountId(array $payload, string $eventType): ?int
    {
        $stripeCustomerId = $eventType === 'customer.deleted'
            ? data_get($payload, 'data.object.id')
            : data_get($payload, 'data.object.customer');

        if (! is_string($stripeCustomerId) || $stripeCustomerId === '') {
            return null;
        }

        $accountId = Account::query()->where('stripe_id', $stripeCustomerId)->value('id');

        return is_numeric($accountId) ? (int) $accountId : null;
    }

    private function projectsEntitlement(string $eventType): bool
    {
        return str_starts_with($eventType, 'customer.subscription.') || $eventType === 'customer.deleted';
    }

    private function knownProjectionAccountId(string $eventId): ?int
    {
        $accountId = StripeWebhookProjection::query()
            ->where('stripe_event_id', $eventId)
            ->value('account_id');

        return is_numeric($accountId) ? (int) $accountId : null;
    }

    private function webhookLockSeconds(): int
    {
        $processingBudget = (int) config('billing.webhook_processing_budget_seconds', 300);
        $lockSeconds = (int) config('billing.webhook_lock_seconds', 600);

        if ($processingBudget < 1 || $lockSeconds <= $processingBudget) {
            throw new \LogicException('The billing webhook lock must exceed its processing budget.');
        }

        return $lockSeconds;
    }

    private function webhookLockWaitSeconds(): int
    {
        $waitSeconds = (int) config('billing.webhook_lock_wait_seconds', 30);

        if ($waitSeconds < 1 || $waitSeconds >= $this->webhookLockSeconds()) {
            throw new \LogicException('The billing webhook lock wait must be positive and shorter than its lease.');
        }

        return $waitSeconds;
    }
}
