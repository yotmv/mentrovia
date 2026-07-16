<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountErasureTarget;
use App\Models\OnboardingDraft;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('onboarding-drafts:prune {--chunk=100 : Maximum drafts to inspect per database batch}')]
#[Description('Delete inactive onboarding drafts after their account-scoped retention period')]
class PruneOnboardingDrafts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if ($chunk === false) {
            $this->components->error('The chunk size must be an integer from 1 to 500.');

            return self::INVALID;
        }

        $deleted = 0;
        $cursor = 0;

        do {
            $candidates = OnboardingDraft::query()
                ->where('id', '>', $cursor)
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'account_id']);

            foreach ($candidates as $candidate) {
                $cursor = $candidate->id;
                $deleted += DB::transaction(function () use ($candidate): int {
                    $account = Account::query()->lockForUpdate()->find($candidate->account_id);

                    if (! $account instanceof Account
                        || $account->erasure_started_at !== null
                        || AccountErasureTarget::accountIsPendingErasure($candidate->account_id)) {
                        return 0;
                    }

                    $draft = OnboardingDraft::query()
                        ->whereKey($candidate->id)
                        ->where('account_id', $account->id)
                        ->where('expires_at', '<=', now())
                        ->lockForUpdate()
                        ->first();

                    return $draft?->delete() === true ? 1 : 0;
                }, attempts: 3);
            }
        } while ($candidates->count() === $chunk);

        $this->components->info(__('Deleted :count expired onboarding drafts.', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
