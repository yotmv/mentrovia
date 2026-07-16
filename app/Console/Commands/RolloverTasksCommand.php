<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\RecurringTaskGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tasks:rollover {--chunk=200 : Businesses to process per database chunk}')]
#[Description('Advance completed recurring tasks into their current due period')]
class RolloverTasksCommand extends Command
{
    public function handle(RecurringTaskGenerator $taskGenerator): int
    {
        $chunkSize = max(1, min(1000, (int) $this->option('chunk')));
        $processed = 0;

        Business::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($businesses) use ($taskGenerator, &$processed): void {
                foreach ($businesses as $business) {
                    $taskGenerator->generateFor($business);
                    $processed++;
                }
            });

        $this->components->info(trans_choice(
            ':count business processed.|:count businesses processed.',
            $processed,
            ['count' => $processed],
        ));

        return self::SUCCESS;
    }
}
