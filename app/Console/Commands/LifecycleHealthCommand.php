<?php

namespace App\Console\Commands;

use App\Services\LifecycleRuntime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lifecycle:health')]
#[Description('Check scheduler heartbeat and dedicated database lifecycle queue health')]
class LifecycleHealthCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LifecycleRuntime $runtime): int
    {
        $snapshot = $runtime->healthSnapshot();
        $maximumHeartbeatAge = max(60, (int) config('photostudio.lifecycle.scheduler_heartbeat_max_age', 180));
        $backlogWarning = max(1, (int) config('photostudio.lifecycle.backlog_warning', 1000));
        $oldestWarning = max(60, (int) config('photostudio.lifecycle.oldest_job_warning_seconds', 900));
        $healthy = $snapshot['scheduler_age_seconds'] !== null
            && $snapshot['scheduler_age_seconds'] <= $maximumHeartbeatAge;

        $this->line(sprintf(
            'Scheduler heartbeat age: %s',
            $snapshot['scheduler_age_seconds'] !== null ? $snapshot['scheduler_age_seconds'].'s' : 'missing',
        ));

        foreach ($snapshot['queues'] as $queue => $metrics) {
            $this->line(sprintf(
                '%s: backlog=%d oldest=%s',
                $queue,
                $metrics['backlog'],
                $metrics['oldest_age_seconds'] !== null ? $metrics['oldest_age_seconds'].'s' : 'none',
            ));

            $healthy = $healthy
                && $metrics['backlog'] <= $backlogWarning
                && ($metrics['oldest_age_seconds'] === null || $metrics['oldest_age_seconds'] <= $oldestWarning);
        }

        if (! $healthy) {
            $this->error('Lifecycle runtime requires operator attention.');

            return self::FAILURE;
        }

        $this->info('Lifecycle runtime is healthy.');

        return self::SUCCESS;
    }
}
