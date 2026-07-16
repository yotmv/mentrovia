<?php

namespace App\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class LifecycleRuntime
{
    public function queueConnection(): string
    {
        return (string) config('photostudio.lifecycle.queue_connection', 'lifecycle-database');
    }

    public function photoQueue(): string
    {
        return (string) config('photostudio.lifecycle.photo_queue', 'photo-lifecycle');
    }

    public function securityQueue(): string
    {
        return (string) config('photostudio.lifecycle.security_queue', 'security-erasure');
    }

    public function assertReady(): void
    {
        $this->assertTransportReady();

        if (! (bool) config('photostudio.lifecycle.require_scheduler_heartbeat', false)) {
            return;
        }

        $heartbeat = DB::table('lifecycle_runtime_heartbeats')
            ->where('name', config('photostudio.lifecycle.scheduler_heartbeat_name'))
            ->value('last_seen_at');
        $maximumAge = max(60, (int) config('photostudio.lifecycle.scheduler_heartbeat_max_age', 180));

        if (! is_string($heartbeat) || now()->diffInSeconds($heartbeat, absolute: true) > $maximumAge) {
            throw new RuntimeException('The lifecycle scheduler heartbeat is unavailable or stale.');
        }
    }

    public function assertTransportReady(): void
    {
        $connectionName = $this->queueConnection();
        $queue = config("queue.connections.{$connectionName}");
        $defaultDatabase = (string) config('database.default');

        if (! is_array($queue)
            || ($queue['driver'] ?? null) !== 'database'
            || ($queue['connection'] ?? null) !== $defaultDatabase
            || ($queue['after_commit'] ?? null) !== false
        ) {
            throw new RuntimeException('Lifecycle queues must use the default database connection with before-commit dispatch.');
        }

        $table = $queue['table'] ?? null;

        if (! is_string($table) || $table === '' || ! Schema::connection($defaultDatabase)->hasTable($table)) {
            throw new RuntimeException('The lifecycle database queue table is unavailable.');
        }

        DB::connection($defaultDatabase)->table($table)->limit(1)->exists();
    }

    public function dispatch(ShouldQueue $job, string $queue): void
    {
        $this->assertTransportReady();

        $database = DB::connection((string) config('database.default'));

        if ($database->transactionLevel() < 1) {
            throw new RuntimeException('Lifecycle jobs must be enqueued inside the domain transaction.');
        }

        if (! method_exists($job, 'onConnection') || ! method_exists($job, 'onQueue') || ! method_exists($job, 'beforeCommit')) {
            throw new RuntimeException('Lifecycle jobs must use Laravel Queueable dispatch controls.');
        }

        $job->onConnection($this->queueConnection());
        $job->onQueue($queue);
        $job->beforeCommit();

        Bus::dispatch($job);
    }

    public function dispatchAfterCommit(ShouldQueue $job, string $queue): void
    {
        $this->assertTransportReady();

        $database = DB::connection((string) config('database.default'));

        if ($database->transactionLevel() < 1) {
            throw new RuntimeException('After-commit lifecycle jobs must be scheduled inside the domain transaction.');
        }

        if (! method_exists($job, 'onConnection') || ! method_exists($job, 'onQueue') || ! method_exists($job, 'afterCommit')) {
            throw new RuntimeException('Lifecycle jobs must use Laravel Queueable dispatch controls.');
        }

        $job->onConnection($this->queueConnection());
        $job->onQueue($queue);
        $job->afterCommit();

        Bus::dispatch($job);
    }

    public function recordSchedulerHeartbeat(): void
    {
        $this->assertTransportReady();

        DB::table('lifecycle_runtime_heartbeats')->updateOrInsert(
            ['name' => config('photostudio.lifecycle.scheduler_heartbeat_name')],
            ['last_seen_at' => now()],
        );
    }

    /**
     * @return array{scheduler_age_seconds: int|null, queues: array<string, array{backlog: int, oldest_age_seconds: int|null}>}
     */
    public function healthSnapshot(): array
    {
        $this->assertTransportReady();

        $heartbeat = DB::table('lifecycle_runtime_heartbeats')
            ->where('name', config('photostudio.lifecycle.scheduler_heartbeat_name'))
            ->value('last_seen_at');
        $queues = [];
        $queueTable = (string) config("queue.connections.{$this->queueConnection()}.table", 'jobs');

        foreach ([$this->securityQueue(), $this->photoQueue()] as $queue) {
            $oldest = DB::table($queueTable)->where('queue', $queue)->min('created_at');
            $oldestAge = null;

            if (is_numeric($oldest)) {
                $oldestTimestamp = (int) $oldest;
                $currentTimestamp = now()->getTimestamp();
                $oldestAge = $currentTimestamp >= $oldestTimestamp
                    ? $currentTimestamp - $oldestTimestamp
                    : 0;
            }

            $queues[$queue] = [
                'backlog' => DB::table($queueTable)->where('queue', $queue)->count(),
                'oldest_age_seconds' => $oldestAge,
            ];
        }

        return [
            'scheduler_age_seconds' => is_string($heartbeat)
                ? (int) now()->diffInSeconds($heartbeat, absolute: true)
                : null,
            'queues' => $queues,
        ];
    }
}
