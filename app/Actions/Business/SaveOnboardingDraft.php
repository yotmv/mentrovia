<?php

namespace App\Actions\Business;

use App\Enums\AccountCapability;
use App\Enums\BusinessOnboardingTrack;
use App\Models\Account;
use App\Models\Business;
use App\Models\OnboardingDraft;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\OnboardingDraftPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveOnboardingDraft
{
    public function __construct(
        private AccountMutationGate $mutationGate,
        private OnboardingDraftPayload $payloads,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function handle(
        Account $account,
        User $actor,
        BusinessOnboardingTrack $track,
        int $resumeStep,
        array $values,
        ?int $expectedRevision,
        ?int $validatedStep = null,
    ): OnboardingDraft {
        if ($resumeStep < 1 || $resumeStep > $track->stepCount()) {
            throw ValidationException::withMessages(['step' => __('The saved onboarding step is invalid.')]);
        }

        return DB::transaction(function () use ($account, $actor, $track, $resumeStep, $values, $expectedRevision, $validatedStep): OnboardingDraft {
            $lockedAccount = $this->mutationGate->lockMemberOrFail(
                $account->id,
                $actor->id,
                AccountCapability::Workspace,
            );
            $draft = OnboardingDraft::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->first();
            $businessExists = Business::query()
                ->where('account_id', $lockedAccount->id)
                ->lockForUpdate()
                ->exists();

            if ($businessExists) {
                throw ValidationException::withMessages([
                    'draft' => __('This workspace already has a company profile.'),
                ]);
            }

            if ($draft instanceof OnboardingDraft) {
                if ($expectedRevision === null || $draft->revision !== $expectedRevision) {
                    throw $this->revisionConflict();
                }

                if ($draft->schema_version !== OnboardingDraftPayload::SCHEMA_VERSION || $draft->track !== $track) {
                    throw ValidationException::withMessages([
                        'draft' => __('This saved profile can no longer be updated. Start over to continue.'),
                    ]);
                }
            } elseif ($expectedRevision !== null) {
                throw $this->revisionConflict();
            }

            $patch = $this->payloads->normalize($values, $track);
            $existingPayload = $draft instanceof OnboardingDraft ? $draft->payload : [];
            $payload = $this->payloads->normalize([
                ...$existingPayload,
                ...$patch,
            ], $track);

            if ($validatedStep !== null) {
                if ($validatedStep < 1 || $validatedStep > $track->stepCount()) {
                    throw ValidationException::withMessages(['step' => __('The onboarding step is invalid.')]);
                }

                $this->payloads->validateStep($payload, $track, $validatedStep);
            } else {
                $this->payloads->validatePartial($payload, $track);
            }

            if (! $draft instanceof OnboardingDraft) {
                return OnboardingDraft::query()->create([
                    'account_id' => $lockedAccount->id,
                    'track' => $track,
                    'current_step' => $resumeStep,
                    'payload' => $payload,
                    'schema_version' => OnboardingDraftPayload::SCHEMA_VERSION,
                    'revision' => 1,
                    'last_saved_by_user_id' => $actor->id,
                    'expires_at' => now()->addDays(180),
                ]);
            }

            $draft->forceFill([
                'current_step' => $resumeStep,
                'payload' => $payload,
                'revision' => $draft->revision + 1,
                'last_saved_by_user_id' => $actor->id,
                'expires_at' => now()->addDays(180),
            ])->save();

            return $draft->refresh();
        }, attempts: 3);
    }

    private function revisionConflict(): ValidationException
    {
        return ValidationException::withMessages([
            'draftRevision' => __('This saved profile changed elsewhere. Reload it before saving again.'),
        ]);
    }
}
