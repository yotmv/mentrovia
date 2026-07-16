<?php

namespace App\Actions\Business;

use App\Enums\AccountCapability;
use App\Enums\BusinessOnboardingTrack;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Models\Account;
use App\Models\Business;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\BusinessProfileSnapshot;
use App\Services\BusinessProfileVersionService;
use App\Services\OnboardingDraftPayload;
use App\Services\RecurringTaskGenerator;
use App\Services\RoadmapPlanSynchronizer;
use App\Services\StageClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeBusinessIntake
{
    public function __construct(
        private AccountMutationGate $mutationGate,
        private OnboardingDraftPayload $payloads,
        private BusinessProfileVersionService $businessVersions,
        private StageClassifier $classifier,
        private RecurringTaskGenerator $taskGenerator,
        private RoadmapPlanSynchronizer $roadmapSynchronizer,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @return array{business: Business, created: bool, conflict: bool}
     */
    public function handle(
        Account $account,
        User $actor,
        BusinessOnboardingTrack $track,
        array $values,
        ?int $expectedDraftRevision,
        ?int $expectedBusinessId,
        ?string $expectedBusinessVersion = null,
    ): array {
        return DB::transaction(function () use ($account, $actor, $track, $values, $expectedDraftRevision): array {
            $context = $this->mutationGate->lockMemberAndOwnerOrFail(
                $account->id,
                $actor->id,
                AccountCapability::Workspace,
            );
            $lockedAccount = $context['account'];
            $draft = OnboardingDraft::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->first();
            $business = Business::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->first();

            if ($business instanceof Business) {
                throw ValidationException::withMessages([
                    'draftRevision' => __('Another person finished this company profile first. Reload to continue with the saved profile.'),
                ]);
            }

            if (! $draft instanceof OnboardingDraft
                || $expectedDraftRevision === null
                || $draft->revision !== $expectedDraftRevision
                || $draft->track !== $track
                || $draft->schema_version !== OnboardingDraftPayload::SCHEMA_VERSION) {
                throw ValidationException::withMessages([
                    'draftRevision' => __('This saved profile changed elsewhere. Reload it before finishing.'),
                ]);
            }

            $draftPayload = $draft->payload;
            $payload = $this->payloads->normalize([
                ...$draftPayload,
                ...$values,
            ], $track);
            $this->payloads->validateComplete($payload, $track);
            $attributes = $this->businessAttributes($payload, $track);
            $business = $lockedAccount->business()->create([
                ...$attributes,
                'user_id' => $actor->id,
            ]);

            $business->stage = $this->classifier->classify($business);
            $business->save();
            $this->businessVersions->recordLocked(
                $business,
                BusinessProfileVersionSource::Onboarding,
                $actor,
                BusinessProfileSnapshot::CORE_FIELDS,
                BusinessProfileSection::cases(),
                ['track' => $track->value],
            );
            $this->taskGenerator->generateFor($business);
            $this->roadmapSynchronizer->syncAfterAuthorizedProfileMutation(
                $business,
                $context['owner_user_id'],
            );
            $draft->delete();

            return [
                'business' => $business->refresh(),
                'created' => true,
                'conflict' => false,
            ];
        }, attempts: 3);
    }

    /**
     * @param  array<string, bool|int|string|null>  $payload
     * @return array<string, bool|int|string|null>
     */
    private function businessAttributes(array $payload, BusinessOnboardingTrack $track): array
    {
        return [
            'name' => $payload['name'] ?? null,
            'desired_name' => $track === BusinessOnboardingTrack::EstablishedCompany ? null : ($payload['desired_name'] ?? null),
            'dba_status' => $payload['dba_status'],
            'industry' => $payload['industry'],
            'started_on' => $payload['started_on'] ?? null,
            'city' => $payload['city'],
            'county' => $payload['county'],
            'state' => 'TX',
            'location_type' => $payload['location_type'],
            'address' => $payload['address'] ?? null,
            'legal_structure' => $payload['legal_structure'],
            'owner_count' => $payload['owner_count'],
            'employee_count' => $payload['employee_count'],
            'uses_contractors' => $payload['uses_contractors'],
            'first_employee_on' => $payload['first_employee_on'] ?? null,
            'sells_taxable_goods' => $payload['sells_taxable_goods'],
            'sells_taxable_services' => $payload['sells_taxable_services'],
            'has_sales_tax_permit' => $payload['has_sales_tax_permit'],
            'has_ein' => $payload['has_ein'],
            'annual_revenue_range' => $payload['annual_revenue_range'],
            'monthly_revenue_range' => $payload['monthly_revenue_range'],
            'first_sale_on' => $payload['first_sale_on'] ?? null,
            'has_business_bank' => $payload['has_business_bank'],
            'has_bookkeeping' => $payload['has_bookkeeping'],
            'has_payroll' => $payload['has_payroll'],
            'filing_confidence' => $payload['filing_confidence'],
        ];
    }
}
