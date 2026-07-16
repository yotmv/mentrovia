<?php

namespace App\Actions\Projects;

use App\Enums\ProjectPermission;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Notifications\ProjectInvitationNotification;
use App\Services\Accounts\AccountMutationGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateProjectInvitation
{
    private const int MaxInvitationsPerMinute = 10;

    private const int InvitationLifetimeDays = 7;

    public function __construct(private AccountMutationGate $accountMutationGate) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(
        Project $project,
        User $inviter,
        string $email,
        ProjectPermission $permission,
    ): ProjectInvitation {
        if ($inviter->cannot('share', $project)) {
            throw new AuthorizationException;
        }

        $normalizedEmail = ProjectInvitation::normalizeEmail($email);

        $result = RateLimiter::attempt(
            'project-invitations:create:'.$inviter->getAuthIdentifier(),
            self::MaxInvitationsPerMinute,
            fn (): array|bool => RateLimiter::attempt(
                $this->recipientRateLimitKey($project, $inviter, $normalizedEmail),
                1,
                fn (): array => $this->createOrRefresh($project, $inviter, $normalizedEmail, $permission),
                60,
            ),
            60,
        );

        if (! is_array($result)) {
            throw ValidationException::withMessages([
                'shareEmail' => __('Too many invitations were sent. Please wait a minute and try again.'),
            ]);
        }

        [$invitation, $plainTextToken] = $result;

        Notification::route('mail', $invitation->email)
            ->notify(new ProjectInvitationNotification($invitation, $plainTextToken));

        return $invitation;
    }

    /** @return array{ProjectInvitation, string} */
    private function createOrRefresh(
        Project $project,
        User $inviter,
        string $normalizedEmail,
        ProjectPermission $permission,
    ): array {
        $plainTextToken = Str::random(64);
        $accountId = Project::query()->whereKey($project->id)->value('account_id');

        if (! is_numeric($accountId)) {
            throw new AuthorizationException;
        }

        $invitation = DB::transaction(function () use ($project, $inviter, $normalizedEmail, $permission, $plainTextToken, $accountId): ProjectInvitation {
            $lockedProject = $this->accountMutationGate->lockProjectManagerOrFail(
                (int) $accountId,
                $project->id,
                $inviter->id,
            );

            ProjectInvitation::query()->upsert(
                [[
                    'public_id' => bin2hex(random_bytes(20)),
                    'project_id' => $lockedProject->id,
                    'invited_by_user_id' => $inviter->id,
                    'accepted_by_user_id' => null,
                    'email' => $normalizedEmail,
                    'permission' => $permission->value,
                    'token_hash' => hash('sha256', $plainTextToken),
                    'expires_at' => now()->addDays(self::InvitationLifetimeDays),
                    'accepted_at' => null,
                    'revoked_at' => null,
                ]],
                ['project_id', 'email'],
                [
                    'invited_by_user_id',
                    'accepted_by_user_id',
                    'permission',
                    'token_hash',
                    'expires_at',
                    'accepted_at',
                    'revoked_at',
                ],
            );

            return ProjectInvitation::query()
                ->whereBelongsTo($lockedProject)
                ->where('email', $normalizedEmail)
                ->sole();
        }, attempts: 3);

        return [$invitation, $plainTextToken];
    }

    private function recipientRateLimitKey(Project $project, User $inviter, string $normalizedEmail): string
    {
        return implode(':', [
            'project-invitations:recipient',
            $inviter->getAuthIdentifier(),
            $project->getKey(),
            hash('sha256', $normalizedEmail),
        ]);
    }
}
