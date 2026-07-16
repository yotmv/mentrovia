<?php

use App\Models\PhotoOperationLease;
use App\Models\PhotoStorageCleanup;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('photos:prune-originals')->daily();
Schedule::command('lifecycle:heartbeat')
    ->everyMinute()
    ->onOneServer();
Schedule::command('photos:reconcile-work', ['--limit' => (int) config('photostudio.reconciliation.limit', 100)])
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('model:prune', ['--model' => [PhotoOperationLease::class, PhotoStorageCleanup::class]])
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('project-invitations:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('tasks:rollover')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('onboarding-drafts:prune')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
