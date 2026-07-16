<?php

namespace App\Actions\Business;

use App\Enums\AccountCapability;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Exceptions\BusinessProfileConflictException;
use App\Models\Account;
use App\Models\Business;
use App\Models\BusinessProfile;
use App\Models\BusinessTask;
use App\Models\RoadmapPlan;
use App\Models\RoadmapPlanItem;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\BusinessProfilePayload;
use App\Services\BusinessProfileSnapshot;
use App\Services\BusinessProfileVersionService;
use App\Services\RecurringTaskGenerator;
use App\Services\RoadmapPlanSynchronizer;
use App\Services\StageClassifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UpdateBusinessProfileSection
{
    public function __construct(
        private AccountMutationGate $mutationGate,
        private BusinessProfilePayload $payloads,
        private BusinessProfileSnapshot $snapshots,
        private BusinessProfileVersionService $versions,
        private StageClassifier $classifier,
        private RecurringTaskGenerator $taskGenerator,
        private RoadmapPlanSynchronizer $roadmapSynchronizer,
    ) {}

    public function baselineEnvelope(Business $business, BusinessProfileSection $section): string
    {
        return Crypt::encryptString(json_encode([
            'schema_version' => BusinessProfileSnapshot::SCHEMA_VERSION,
            'account_id' => $business->account_id,
            'business_id' => $business->id,
            'profile_revision' => $business->profile_revision,
            'section' => $section->value,
            'values' => $this->snapshots->businessFacts($business),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>|null  $sourceMetadata
     * @return array{business: Business, changed: bool, changed_fields: list<string>}
     */
    public function handle(
        Account $account,
        Business $business,
        User $actor,
        BusinessProfileSection $section,
        array $values,
        string $baselineEnvelope,
        BusinessProfileVersionSource $source = BusinessProfileVersionSource::Manual,
        ?array $sourceMetadata = null,
    ): array {
        $validated = $this->payloads->validateSection($section, $values);
        $baseline = $this->decryptBaseline($baselineEnvelope, $account, $business, $section);

        return DB::transaction(function () use ($account, $business, $actor, $section, $validated, $baseline, $source, $sourceMetadata): array {
            $context = $this->mutationGate->lockMemberAndOwnerOrFail(
                $account->id,
                $actor->id,
                AccountCapability::Workspace,
            );
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->where('account_id', $context['account']->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBusiness instanceof Business) {
                throw new AuthorizationException;
            }

            $this->lockProfileConsumers($lockedBusiness);
            $this->versions->ensureBaselineLocked($lockedBusiness);
            $current = $this->snapshots->businessFacts($lockedBusiness);
            $patch = [];

            foreach ($section->fields() as $field) {
                if (array_key_exists($field, $validated)
                    && ! $this->same($validated[$field], $baseline['values'][$field] ?? null)) {
                    $patch[$field] = $validated[$field];
                }
            }

            $resultingEmployeeCount = (int) ($patch['employee_count'] ?? $current['employee_count']);

            if ($resultingEmployeeCount === 0) {
                if (($patch['first_employee_on'] ?? $current['first_employee_on']) !== null) {
                    $patch['first_employee_on'] = null;
                }

                if (($patch['has_payroll'] ?? $current['has_payroll']) !== false) {
                    $patch['has_payroll'] = false;
                }
            }

            $patch = array_filter(
                $patch,
                fn (mixed $value, string $field): bool => ! $this->same($value, $current[$field] ?? null),
                ARRAY_FILTER_USE_BOTH,
            );

            $conflicts = [];

            foreach ($patch as $field => $yourValue) {
                if (! $this->same($current[$field] ?? null, $baseline['values'][$field] ?? null)) {
                    $conflicts[$field] = [
                        'current' => $current[$field] ?? null,
                        'yours' => $yourValue,
                    ];
                }
            }

            if ($conflicts !== []) {
                throw new BusinessProfileConflictException($conflicts, $patch);
            }

            if ($patch === []) {
                return [
                    'business' => $lockedBusiness->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                ];
            }

            $lockedBusiness->forceFill($patch);
            $previousStage = $lockedBusiness->stage;
            $lockedBusiness->stage = $this->classifier->classify($lockedBusiness);
            $lockedBusiness->save();
            $lockedBusiness->unsetRelation('profileAnswers');
            $changedFields = array_keys($patch);

            if ($lockedBusiness->stage !== $previousStage) {
                $changedFields[] = 'stage';
            }

            $sections = collect($changedFields)
                ->map(fn (string $field): ?BusinessProfileSection => BusinessProfileSection::forField($field))
                ->filter()
                ->unique(fn (BusinessProfileSection $value): string => $value->value)
                ->values()
                ->all();

            if ($lockedBusiness->stage !== $previousStage) {
                $sections[] = BusinessProfileSection::CompanyBasics;
            }

            $this->versions->recordLocked(
                $lockedBusiness,
                $source,
                $actor,
                $changedFields,
                array_values($sections),
                $sourceMetadata,
            );
            $this->taskGenerator->generateFor($lockedBusiness);
            $this->roadmapSynchronizer->syncAfterAuthorizedProfileMutation(
                $lockedBusiness,
                $context['owner_user_id'],
            );

            return [
                'business' => $lockedBusiness->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
            ];
        }, attempts: 3);
    }

    /**
     * @return array{values: array<string, bool|int|string|null>}
     */
    private function decryptBaseline(
        string $envelope,
        Account $account,
        Business $business,
        BusinessProfileSection $section,
    ): array {
        try {
            $payload = json_decode(Crypt::decryptString($envelope), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['profile' => __('This profile editor baseline is invalid. Reload before saving.')]);
        }

        if (! is_array($payload)
            || ($payload['schema_version'] ?? null) !== BusinessProfileSnapshot::SCHEMA_VERSION
            || ($payload['account_id'] ?? null) !== $account->id
            || ($payload['business_id'] ?? null) !== $business->id
            || ($payload['section'] ?? null) !== $section->value
            || ! is_array($payload['values'] ?? null)) {
            throw ValidationException::withMessages(['profile' => __('This profile editor baseline is invalid. Reload before saving.')]);
        }

        return ['values' => $payload['values']];
    }

    private function lockProfileConsumers(Business $business): void
    {
        BusinessProfile::query()->whereBelongsTo($business)->orderBy('id')->lockForUpdate()->get();
        BusinessTask::query()->whereBelongsTo($business)->orderBy('id')->lockForUpdate()->get();
        $plan = RoadmapPlan::query()->whereBelongsTo($business)->lockForUpdate()->first();

        if ($plan instanceof RoadmapPlan) {
            RoadmapPlanItem::query()
                ->where('roadmap_plan_id', $plan->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
        }
    }

    private function same(mixed $first, mixed $second): bool
    {
        return $first === $second;
    }
}
