<?php

namespace App\Notifications;

use App\Models\AccountErasureTarget;
use App\Models\AccountInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class AccountInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public AccountInvitation $invitation,
        #[\SensitiveParameter] public string $plainTextToken,
    ) {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $currentInvitation = AccountInvitation::query()
            ->whereKey($this->invitation->id)
            ->first();

        if ($currentInvitation === null
            || ! $currentInvitation->isPending()
            || ! $currentInvitation->tokenMatches($this->plainTextToken)
            || AccountErasureTarget::accountIsPendingErasure($currentInvitation->account_id)) {
            return false;
        }

        $this->invitation = $currentInvitation;

        return true;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = URL::temporarySignedRoute(
            'account-invitations.show',
            $this->invitation->expires_at,
            [
                'accountInvitation' => $this->invitation,
                'token' => $this->plainTextToken,
            ],
        );

        return (new MailMessage)
            ->subject(__('You have been invited to a Mentrovia workspace'))
            ->line(__('You have been invited to join the workspace ":account" as :role.', [
                'account' => $this->invitation->account->name,
                'role' => $this->invitation->role->value,
            ]))
            ->line(__('Sign in or create an account with this email address, verify it, then accept the invitation.'))
            ->action(__('Review invitation'), $acceptUrl)
            ->line(__('This invitation expires on :date. If you were not expecting it, you can ignore this email.', [
                'date' => $this->invitation->expires_at->toFormattedDateString(),
            ]));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
