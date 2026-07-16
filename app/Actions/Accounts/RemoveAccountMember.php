<?php

namespace App\Actions\Accounts;

use App\Enums\AccountRole;
use App\Models\Account;
use App\Models\Business;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RemoveAccountMember
{
    public function __construct(
        private CurrentAccount $currentAccount,
        private AccountMutationGate $accountMutationGate,
        private ProvisionAccountEntitlement $provisionEntitlement,
    ) {}

    /** @throws AuthorizationException */
    public function handle(Account $account, User $actor, User $target): Account
    {
        $replacement = DB::transaction(function () use ($account, $actor, $target): Account {
            $context = $this->accountMutationGate->lockMemberAndUsersOrFail(
                $account->id,
                $actor->id,
                [$target->id],
            );
            $lockedAccount = $context['account'];
            $lockedActor = $context['users'][$actor->id];
            $lockedTarget = $context['users'][$target->id];

            if (! isset($context['roles'][$target->id])) {
                throw new AuthorizationException;
            }

            $actorRole = $context['roles'][$actor->id];
            $targetRole = $context['roles'][$target->id];

            if (! $this->mayRemove($lockedActor, $lockedTarget, $actorRole, $targetRole)) {
                throw new AuthorizationException;
            }

            $this->clearRoadmapAssignments($lockedAccount, $lockedTarget);

            DB::table('account_user')
                ->where('account_id', $account->id)
                ->where('user_id', $target->id)
                ->delete();

            return $this->repairCurrentAccount($lockedTarget, $account->id);
        }, attempts: 3);

        $target->setAttribute('current_account_id', $replacement->id);

        if ($actor->id === $target->id) {
            $this->currentAccount->forget();
            $this->currentAccount->resolve($target);
        }

        return $replacement;
    }

    private function clearRoadmapAssignments(Account $account, User $target): void
    {
        $businessIds = Business::query()
            ->where('account_id', $account->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        $planIds = RoadmapPlan::query()
            ->whereIn('business_id', $businessIds)
            ->orderBy('business_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
        $items = RoadmapPlanItem::query()
            ->whereIn('roadmap_plan_id', $planIds)
            ->orderBy('roadmap_plan_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($items as $item) {
            if ($item->assigned_user_id === $target->id) {
                $item->update(['assigned_user_id' => null]);
            }
        }
    }

    private function mayRemove(
        User $actor,
        User $target,
        ?AccountRole $actorRole,
        ?AccountRole $targetRole,
    ): bool {
        if ($targetRole === null || $targetRole === AccountRole::Owner) {
            return false;
        }

        if ($actor->id === $target->id) {
            return in_array($actorRole, [AccountRole::Admin, AccountRole::Member], true);
        }

        return $actorRole === AccountRole::Owner
            || ($actorRole === AccountRole::Admin && $targetRole === AccountRole::Member);
    }

    private function repairCurrentAccount(User $lockedTarget, int $removedAccountId): Account
    {
        if ($lockedTarget->current_account_id !== $removedAccountId) {
            return Account::query()->findOrFail($lockedTarget->current_account_id);
        }

        $replacementAccountId = DB::table('account_user')
            ->where('user_id', $lockedTarget->id)
            ->where('account_id', '!=', $removedAccountId)
            ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('account_id')
            ->lockForUpdate()
            ->value('account_id');

        if (is_numeric($replacementAccountId)) {
            $replacement = Account::query()->findOrFail((int) $replacementAccountId);
        } else {
            $replacement = Account::query()->create(['name' => $lockedTarget->name.' workspace']);
            DB::table('account_user')->insert([
                'account_id' => $replacement->id,
                'user_id' => $lockedTarget->id,
                'role' => AccountRole::Owner->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->provisionEntitlement->handle($replacement);
        }

        $lockedTarget->forceFill(['current_account_id' => $replacement->id])->save();

        return $replacement;
    }
}
