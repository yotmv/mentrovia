<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Accounts\AccountWorkGate;
use App\Services\BusinessProfileVersionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('business-profiles:backfill-versions {--limit=1000 : Maximum businesses to inspect} {--chunk=100 : Rows per bounded query}')]
#[Description('Create encrypted immutable baseline versions for legacy business profiles')]
class BackfillBusinessProfileVersions extends Command
{
    public function handle(AccountWorkGate $workGate, BusinessProfileVersionService $versions): int
    {
        $limit = max(1, min(10_000, (int) $this->option('limit')));
        $chunk = max(1, min(500, (int) $this->option('chunk')));
        $inspected = 0;
        $created = 0;
        $lastId = 0;

        while ($inspected < $limit) {
            $businesses = Business::query()
                ->whereDoesntHave('profileVersions')
                ->whereHas('account', fn ($query) => $query->whereNull('erasure_started_at'))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(min($chunk, $limit - $inspected))
                ->get(['id', 'account_id']);

            if ($businesses->isEmpty()) {
                break;
            }

            foreach ($businesses as $business) {
                $lastId = $business->id;
                $inspected++;
                $wasCreated = DB::transaction(function () use ($business, $workGate, $versions): bool {
                    $account = $workGate->lockActive($business->account_id);

                    if ($account === null) {
                        return false;
                    }

                    $lockedBusiness = Business::query()
                        ->whereKey($business->id)
                        ->where('account_id', $account->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $lockedBusiness instanceof Business || $lockedBusiness->profileVersions()->exists()) {
                        return false;
                    }

                    $versions->ensureBaselineLocked($lockedBusiness);

                    return true;
                }, attempts: 3);
                $created += $wasCreated ? 1 : 0;
            }
        }

        $this->components->info("Inspected {$inspected} businesses; created {$created} encrypted baselines.");

        return self::SUCCESS;
    }
}
