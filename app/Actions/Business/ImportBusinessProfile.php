<?php

namespace App\Actions\Business;

use App\Enums\AccountCapability;
use App\Enums\AccountRole;
use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
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

final class ImportBusinessProfile
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

    /**
     * @param  array<string, bool>  $selections
     * @return array{business: Business, changed_fields: list<string>}
     */
    public function handle(Account $account, Business $business, User $actor, string $envelope, array $selections): array
    {
        $preview = $this->decryptPreview($envelope, $account, $business);

        return DB::transaction(function () use ($account, $business, $actor, $preview, $selections): array {
            $context = $this->mutationGate->lockMemberAndOwnerOrFail($account->id, $actor->id, AccountCapability::Workspace);

            if (! in_array($context['roles'][$actor->id], [AccountRole::Owner, AccountRole::Admin], true)) {
                throw new AuthorizationException;
            }

            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->where('account_id', $context['account']->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedBusiness instanceof Business) {
                throw new AuthorizationException;
            }

            if ($lockedBusiness->profile_revision !== $preview['profile_revision']
                || ! hash_equals($preview['profile_fingerprint'], $this->versions->issue($lockedBusiness))) {
                throw ValidationException::withMessages(['csvUpload' => __('The company profile changed after this preview. Upload the CSV again.')]);
            }

            $this->lockProfileConsumers($lockedBusiness);
            $this->versions->ensureBaselineLocked($lockedBusiness);
            $proposals = $this->payloads->validateImport($preview['proposals']);
            $current = $this->snapshots->businessFacts($lockedBusiness);
            $patch = [];

            foreach ($proposals as $field => $value) {
                if (($selections[$field] ?? false) === true && ($current[$field] ?? null) !== $value) {
                    $patch[$field] = $value;
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
                fn (mixed $value, string $field): bool => $value !== ($current[$field] ?? null),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($patch === []) {
                throw ValidationException::withMessages(['csvUpload' => __('Select at least one field that changes the current profile.')]);
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
                ->unique(fn (BusinessProfileSection $section): string => $section->value)
                ->values()
                ->all();

            if ($lockedBusiness->stage !== $previousStage) {
                $sections[] = BusinessProfileSection::CompanyBasics;
            }

            $selectedKeys = collect($selections)
                ->filter(fn (bool $selected, string $field): bool => $selected && array_key_exists($field, $proposals))
                ->keys()
                ->sort()
                ->values()
                ->all();
            $this->versions->recordLocked(
                $lockedBusiness,
                BusinessProfileVersionSource::CsvImport,
                $actor,
                $changedFields,
                array_values($sections),
                [
                    'source_fingerprint' => $preview['source_fingerprint'],
                    'recognized_count' => $preview['recognized_count'],
                    'unknown_count' => $preview['unknown_count'],
                    'selected_count' => count($selectedKeys),
                    'selected_field_keys' => $selectedKeys,
                ],
            );
            $this->taskGenerator->generateFor($lockedBusiness);
            $this->roadmapSynchronizer->syncAfterAuthorizedProfileMutation($lockedBusiness, $context['owner_user_id']);

            return ['business' => $lockedBusiness->refresh(), 'changed_fields' => $changedFields];
        }, attempts: 3);
    }

    /** @return array{profile_revision: int, profile_fingerprint: string, proposals: array<string, mixed>, source_fingerprint: string, recognized_count: int, unknown_count: int} */
    private function decryptPreview(string $envelope, Account $account, Business $business): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($envelope), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['csvUpload' => __('The import preview is invalid. Upload the CSV again.')]);
        }

        if (! is_array($payload)
            || ($payload['schema_version'] ?? null) !== BusinessProfileSnapshot::SCHEMA_VERSION
            || ($payload['account_id'] ?? null) !== $account->id
            || ($payload['business_id'] ?? null) !== $business->id
            || ! is_int($payload['profile_revision'] ?? null)
            || ! is_string($payload['profile_fingerprint'] ?? null)
            || strlen($payload['profile_fingerprint']) !== 64
            || ! is_array($payload['proposals'] ?? null)
            || ! is_string($payload['source_fingerprint'] ?? null)
            || strlen($payload['source_fingerprint']) !== 64
            || ! is_int($payload['recognized_count'] ?? null)
            || ! is_int($payload['unknown_count'] ?? null)) {
            throw ValidationException::withMessages(['csvUpload' => __('The import preview is invalid. Upload the CSV again.')]);
        }

        return $payload;
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
