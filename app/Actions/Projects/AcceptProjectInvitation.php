<?php

namespace App\Actions\Projects;

use App\Models\Account;
use App\Models\AccountErasureTarget;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class AcceptProjectInvitation
{
    /**
     * @throws AuthorizationException
     * @throws GoneHttpException
     */
    public function handle(
        ProjectInvitation $invitation,
        User $recipient,
        #[\SensitiveParameter]
        string $plainTextToken,
    ): Project {
        if ($recipient->account_erasure_started_at !== null
            || ! hash_equals($invitation->email, ProjectInvitation::normalizeEmail($recipient->email))) {
            throw new AuthorizationException;
        }

        $accountId = Project::query()->whereKey($invitation->project_id)->value('account_id');

        if (! is_numeric($accountId)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($invitation, $recipient, $plainTextToken, $accountId): Project {
            $account = Account::query()->lockForUpdate()->findOrFail((int) $accountId);

            if ($account->erasure_started_at !== null || AccountErasureTarget::accountIsPendingErasure($account->id)) {
                throw new GoneHttpException(__('This workspace is no longer accepting invitations.'));
            }

            $lockedRecipient = User::query()->lockForUpdate()->findOrFail($recipient->id);
            $project = Project::query()->lockForUpdate()->findOrFail($invitation->project_id);
            $lockedInvitation = ProjectInvitation::query()
                ->lockForUpdate()
                ->findOrFail($invitation->id);

            if ($lockedRecipient->account_erasure_started_at !== null
                || ! hash_equals($lockedInvitation->email, ProjectInvitation::normalizeEmail($lockedRecipient->email))) {
                throw new AuthorizationException;
            }

            if (! $lockedInvitation->tokenMatches($plainTextToken)) {
                throw new AuthorizationException;
            }

            if (! $lockedInvitation->isPending()) {
                throw new GoneHttpException(__('This invitation is no longer available.'));
            }

            if ($project->account_id !== $account->id || $lockedInvitation->project_id !== $project->id) {
                throw new AuthorizationException;
            }

            if (! $lockedRecipient->belongsToAccount($project->account)) {
                $project->sharedUsers()->syncWithoutDetaching([
                    $lockedRecipient->id => ['permission' => $lockedInvitation->permission->value],
                ]);
            }

            $lockedInvitation->update([
                'accepted_by_user_id' => $lockedRecipient->id,
                'accepted_at' => now(),
            ]);

            return $project;
        }, attempts: 3);
    }
}
