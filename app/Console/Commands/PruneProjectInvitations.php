<?php

namespace App\Console\Commands;

use App\Models\ProjectInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('project-invitations:prune {--retention-hours=24 : Hours to retain accepted and revoked invitations}')]
#[Description('Delete expired invitations and terminal invitations past their retention window')]
class PruneProjectInvitations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionHours = filter_var($this->option('retention-hours'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);

        if ($retentionHours === false) {
            $this->components->error('The retention hours must be a non-negative integer.');

            return self::INVALID;
        }

        $expiredAt = now();
        $terminalCutoff = now()->subHours($retentionHours);

        $deleted = ProjectInvitation::query()
            ->where(function (Builder $query) use ($expiredAt, $terminalCutoff): void {
                $query->where('expires_at', '<=', $expiredAt)
                    ->orWhere('accepted_at', '<=', $terminalCutoff)
                    ->orWhere('revoked_at', '<=', $terminalCutoff);
            })
            ->delete();

        $this->components->info(__('Deleted :count stale project invitations.', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
