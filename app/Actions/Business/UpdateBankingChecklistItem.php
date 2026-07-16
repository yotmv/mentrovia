<?php

namespace App\Actions\Business;

use App\Enums\AccountCapability;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Models\Business;
use App\Models\BusinessProfile;
use App\Models\BusinessTask;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\BankingSetupGuide;
use App\Services\BusinessProfileVersionService;
use App\Services\RecurringTaskGenerator;
use App\Services\RoadmapPlanSynchronizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdateBankingChecklistItem
{
    public function __construct(
        private AccountMutationGate $mutationGate,
        private BusinessProfileVersionService $versions,
        private RecurringTaskGenerator $taskGenerator,
        private RoadmapPlanSynchronizer $roadmapSynchronizer,
    ) {}

    public function handle(Business $business, User $actor, string $key, bool $completed): bool
    {
        return DB::transaction(function () use ($business, $actor, $key, $completed): bool {
            $context = $this->mutationGate->lockMemberAndOwnerOrFail(
                $business->account_id,
                $actor->id,
                AccountCapability::Workspace,
            );
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->where('account_id', $context['account']->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBusiness instanceof Business || ! BankingSetupGuide::canCompleteKey($key)) {
                throw new AuthorizationException;
            }

            $this->lockProfileConsumers($lockedBusiness);
            $this->versions->ensureBaselineLocked($lockedBusiness);
            $changedField = $key === 'dedicated-checking'
                ? $this->updateBankingFact($lockedBusiness, $completed)
                : $this->updateProfileAnswer($lockedBusiness, $key, $completed);

            if ($changedField === null) {
                return false;
            }

            $lockedBusiness->unsetRelation('profileAnswers');
            $this->versions->recordLocked(
                $lockedBusiness,
                BusinessProfileVersionSource::Workflow,
                $actor,
                [$changedField],
                [BusinessProfileSection::OperationsReadiness],
                ['workflow' => 'banking_checklist', 'item_key' => $key],
            );
            $this->taskGenerator->generateFor($lockedBusiness);
            $this->roadmapSynchronizer->syncAfterAuthorizedProfileMutation($lockedBusiness, $context['owner_user_id']);

            return true;
        }, attempts: 3);
    }

    private function updateBankingFact(Business $business, bool $completed): ?string
    {
        if ($business->has_business_bank === $completed) {
            return null;
        }

        $business->forceFill(['has_business_bank' => $completed])->save();

        return 'has_business_bank';
    }

    private function updateProfileAnswer(Business $business, string $key, bool $completed): ?string
    {
        $questionKey = BankingSetupGuide::profileQuestionKey($key);
        $answer = BusinessProfile::query()
            ->where('business_id', $business->id)
            ->where('question_key', $questionKey)
            ->lockForUpdate()
            ->first();

        if (! $completed) {
            if (! $answer instanceof BusinessProfile) {
                return null;
            }

            $answer->delete();

            return 'profile_answers.'.$questionKey;
        }

        if ($answer instanceof BusinessProfile) {
            if ($answer->answer_value === BankingSetupGuide::DoneValue && $answer->confidence === 'user_confirmed') {
                return null;
            }

            $answer->forceFill([
                'answer_value' => BankingSetupGuide::DoneValue,
                'confidence' => 'user_confirmed',
            ])->save();

            return 'profile_answers.'.$questionKey;
        }

        BusinessProfile::query()->create([
            'business_id' => $business->id,
            'question_key' => $questionKey,
            'answer_value' => BankingSetupGuide::DoneValue,
            'confidence' => 'user_confirmed',
        ]);

        return 'profile_answers.'.$questionKey;
    }

    private function lockProfileConsumers(Business $business): void
    {
        BusinessProfile::query()->whereBelongsTo($business)->orderBy('id')->lockForUpdate()->get();
        BusinessTask::query()->whereBelongsTo($business)->orderBy('id')->lockForUpdate()->get();
        $plan = RoadmapPlan::query()->whereBelongsTo($business)->lockForUpdate()->first();

        if ($plan instanceof RoadmapPlan) {
            RoadmapPlanItem::query()->where('roadmap_plan_id', $plan->id)->orderBy('id')->lockForUpdate()->get();
        }
    }
}
