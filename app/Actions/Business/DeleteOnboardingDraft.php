<?php

namespace App\Actions\Business;

use App\Enums\AccountCapability;
use App\Models\Account;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteOnboardingDraft
{
    public function __construct(private AccountMutationGate $mutationGate) {}

    public function handle(Account $account, User $actor, int $expectedRevision): void
    {
        DB::transaction(function () use ($account, $actor, $expectedRevision): void {
            $lockedAccount = $this->mutationGate->lockMemberOrFail(
                $account->id,
                $actor->id,
                AccountCapability::Workspace,
            );
            $draft = OnboardingDraft::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->first();

            if (! $draft instanceof OnboardingDraft || $draft->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'draftRevision' => __('This saved profile changed elsewhere. Reload it before starting over.'),
                ]);
            }

            $draft->delete();
        }, attempts: 3);
    }
}
