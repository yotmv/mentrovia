<?php

use App\Actions\Accounts\StartWorkspaceErasure;
use App\Enums\AiAuditEvent;
use App\Jobs\EraseWorkspaceData;
use App\Models\Account;
use App\Models\AiOperationAudit;
use App\Models\User;
use App\Models\WorkspaceErasureProgress;
use App\Services\Billing\StripeCustomerTeardown;
use App\Services\WorkspaceErasureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function Pest\Laravel\mock;

test('workspace deletion proves Stripe customer teardown outside its final transaction and preserves AI audits', function () {
    Storage::fake('s3');
    Queue::fake();
    config(['photostudio.workspace_erasure_chunks_per_job' => 100]);
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_erasure'])->save();
    $audit = AiOperationAudit::query()->create([
        'operation_id' => (string) Str::uuid7(),
        'account_id' => $account->id,
        'actor_user_id' => $owner->id,
        'event' => AiAuditEvent::Succeeded,
        'occurred_at' => now(),
    ]);
    $proof = hash('sha256', 'stripe_customer_deleted|cus_erasure');
    $baselineTransactionLevel = DB::transactionLevel();
    $teardown = mock(StripeCustomerTeardown::class);
    $teardown->shouldReceive('delete')
        ->once()
        ->with('cus_erasure')
        ->andReturnUsing(function () use ($proof, $baselineTransactionLevel): string {
            expect(DB::transactionLevel())->toBe($baselineTransactionLevel);

            return $proof;
        });
    $this->app->instance(StripeCustomerTeardown::class, $teardown);
    $this->actingAs($owner);
    $accountId = $account->id;
    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');

    (new EraseWorkspaceData($accountId, (string) $progress->dispatch_token))
        ->handle(app(WorkspaceErasureService::class));

    $completed = WorkspaceErasureProgress::query()->where('account_id', $accountId)->sole();

    expect(Account::query()->whereKey($accountId)->exists())->toBeFalse()
        ->and($completed->phase)->toBe('completed')
        ->and($completed->billing_customer_id)->toBeNull()
        ->and($completed->billing_teardown_proof)->toBe($proof)
        ->and($completed->billing_teardown_completed_at)->not->toBeNull()
        ->and(AiOperationAudit::query()->find($audit->id)?->account_id)->toBe($accountId);
});

test('a Stripe teardown failure leaves deletion resumable and account data intact until proof succeeds', function () {
    Storage::fake('s3');
    Queue::fake();
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill(['stripe_id' => 'cus_retry'])->save();
    $proof = hash('sha256', 'stripe_customer_deleted|cus_retry');
    $teardown = mock(StripeCustomerTeardown::class);
    $teardown->shouldReceive('delete')
        ->once()
        ->ordered()
        ->with('cus_retry')
        ->andThrow(new RuntimeException('temporary Stripe failure'));
    $teardown->shouldReceive('delete')
        ->once()
        ->ordered()
        ->with('cus_retry')
        ->andReturn($proof);
    $this->app->instance(StripeCustomerTeardown::class, $teardown);
    $this->actingAs($owner);
    $accountId = $account->id;
    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');
    $service = app(WorkspaceErasureService::class);
    $token = (string) $progress->dispatch_token;

    expect($service->claim($accountId, $token))->toBeTrue();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $current = WorkspaceErasureProgress::query()->where('account_id', $accountId)->sole();

        if ($current->phase === 'teardown_billing') {
            break;
        }

        $service->resume($accountId, $token);
    }

    expect(fn () => $service->resume($accountId, $token))
        ->toThrow(RuntimeException::class, 'temporary Stripe failure');

    $failed = WorkspaceErasureProgress::query()->where('account_id', $accountId)->sole();
    expect($failed->phase)->toBe('teardown_billing')
        ->and($failed->billing_customer_id)->toBe('cus_retry')
        ->and($failed->billing_teardown_completed_at)->toBeNull()
        ->and(Account::query()->whereKey($accountId)->exists())->toBeTrue();

    $service->resume($accountId, $token);
    $service->resume($accountId, $token);

    expect(Account::query()->whereKey($accountId)->exists())->toBeFalse()
        ->and(WorkspaceErasureProgress::query()->where('account_id', $accountId)->value('billing_teardown_proof'))->toBe($proof);
});

test('workspace deletion waits while a checkout request may still be creating a Stripe customer', function () {
    Storage::fake('s3');
    Queue::fake();
    config(['photostudio.workspace_erasure_chunks_per_job' => 100]);
    $owner = User::factory()->create();
    $account = $owner->currentAccount;
    $account->forceFill([
        'billing_checkout_token' => '3da43c3e-4c51-4c9d-879c-3d42077b24bb',
        'billing_checkout_session_id' => null,
        'billing_checkout_expires_at' => now()->addMinutes(10),
    ])->save();
    $teardown = mock(StripeCustomerTeardown::class);
    $teardown->shouldNotReceive('delete');
    $teardown->shouldNotReceive('missingCustomerProof');
    $this->app->instance(StripeCustomerTeardown::class, $teardown);
    $this->actingAs($owner);
    $accountId = $account->id;
    $progress = app(StartWorkspaceErasure::class)->handle($account, $owner, $account->name, 'password');

    (new EraseWorkspaceData($accountId, (string) $progress->dispatch_token))
        ->handle(app(WorkspaceErasureService::class));

    $waiting = WorkspaceErasureProgress::query()->where('account_id', $accountId)->sole();

    expect(Account::query()->whereKey($accountId)->exists())->toBeTrue()
        ->and($waiting->phase)->toBe('teardown_billing')
        ->and($waiting->billing_teardown_completed_at)->toBeNull();
});

test('billing teardown proofs are keyed and do not expose the Stripe customer identifier', function () {
    config(['app.key' => 'base64:billing-proof-test-key']);
    $teardown = app(StripeCustomerTeardown::class);
    $proof = $teardown->missingCustomerProof();

    expect($proof)->toBe(hash_hmac(
        'sha256',
        'stripe_customer_deleted|no_stripe_customer',
        'base64:billing-proof-test-key',
    ))->not->toContain('no_stripe_customer');
});
