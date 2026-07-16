<?php

use App\Actions\Users\EraseUserAccount;
use App\Jobs\EraseUserAccountData;
use App\Jobs\EraseWorkspaceData;
use App\Models\AccountErasureProgress;
use App\Models\AccountErasureTarget;
use App\Models\User;
use App\Models\WorkspaceErasureProgress;
use App\Services\WorkspaceErasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function advanceUserErasureToWorkspaceHandoff(int $userId, int $maxAttempts = 200): void
{
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        if (User::query()->find($userId) === null) {
            return;
        }

        if (AccountErasureProgress::query()->where('user_id', $userId)->value('phase') === 'workspace_erasure') {
            return;
        }

        (new EraseUserAccountData($userId))->handle(app(EraseUserAccount::class));
    }

    throw new RuntimeException('User erasure did not reach the workspace handoff.');
}

function simulateWorkspaceErasureAndFinishUser(int $userId): void
{
    advanceUserErasureToWorkspaceHandoff($userId);

    (new EraseUserAccountData($userId))->handle(app(EraseUserAccount::class));

    $targetAccountIds = AccountErasureTarget::query()
        ->where('user_id', $userId)
        ->where('resource_type', 'account')
        ->pluck('resource_id');

    foreach ($targetAccountIds as $accountId) {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $workspaceProgress = WorkspaceErasureProgress::query()->where('account_id', $accountId)->first();

            if ($workspaceProgress?->completed_at !== null) {
                break;
            }

            if (! is_string($workspaceProgress?->dispatch_token)) {
                throw new RuntimeException('Workspace erasure was not dispatched.');
            }

            (new EraseWorkspaceData((int) $accountId, $workspaceProgress->dispatch_token))
                ->handle(app(WorkspaceErasureService::class));
        }

        if (WorkspaceErasureProgress::query()->where('account_id', $accountId)->whereNotNull('completed_at')->doesntExist()) {
            throw new RuntimeException('Workspace erasure did not complete.');
        }
    }

    for ($attempt = 0; $attempt < 3 && User::query()->find($userId) !== null; $attempt++) {
        (new EraseUserAccountData($userId))->handle(app(EraseUserAccount::class));
    }

    if (User::query()->find($userId) !== null) {
        throw new RuntimeException('User erasure did not finish after workspace erasure.');
    }
}
