<?php

namespace App\Console\Commands;

use App\Services\LifecycleRuntime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('lifecycle:heartbeat')]
#[Description('Record the scheduler heartbeat for security and photo lifecycle work')]
class LifecycleHeartbeatCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LifecycleRuntime $runtime): int
    {
        $runtime->recordSchedulerHeartbeat();

        $this->info('Lifecycle scheduler heartbeat recorded.');

        return self::SUCCESS;
    }
}
