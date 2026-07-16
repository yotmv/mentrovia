<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePhotoWithModel;
use App\Jobs\RunPhotoGenerationBatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('security:purge-legacy-photo-generation-payloads
    {--dry-run : Report matching records without deleting them}
    {--workers-stopped : Confirm queue producers and workers are stopped for the purge window}')]
#[Description('Remove legacy queued photo-generation payloads and failed records that may contain customer prompts')]
class PurgeLegacyPhotoGenerationPayloads extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('dry-run') && ! $this->option('workers-stopped')) {
            $this->components->error('Stop queue producers and workers, then rerun with --workers-stopped. Use --dry-run while workers are active.');

            return self::INVALID;
        }

        $queueConnectionName = (string) config('queue.default');
        $queueConfig = config("queue.connections.{$queueConnectionName}");
        $failedDriver = (string) config('queue.failed.driver');
        $pendingSupported = is_array($queueConfig) && ($queueConfig['driver'] ?? null) === 'database';
        $failedSupported = str_starts_with($failedDriver, 'database');

        if (! $pendingSupported) {
            $this->components->warn('Pending jobs use a non-database backend and were not scanned. Drain or purge that queue with its native tooling.');
        }

        if (! $failedSupported) {
            $this->components->warn('Failed jobs use a non-database backend and were not scanned. Purge that backend with its native tooling.');
        }

        if (! $pendingSupported && ! $failedSupported) {
            $this->components->error('No configured queue backend can be safely processed by this command.');

            return self::INVALID;
        }

        $delete = ! $this->option('dry-run');
        $pendingCount = 0;
        $failedCount = 0;

        if ($pendingSupported) {
            $pendingConnection = DB::connection($queueConfig['connection'] ?? null);
            $pendingTable = (string) ($queueConfig['table'] ?? 'jobs');

            if (! $pendingConnection->getSchemaBuilder()->hasTable($pendingTable)) {
                $this->components->error('The configured database queue table does not exist.');

                return self::INVALID;
            }

            $pendingCount = $this->processMatches(
                $pendingConnection,
                $pendingTable,
                fn (string $payload): bool => $this->isLegacyPendingPayload($payload),
                $delete,
            );
        }

        if ($failedSupported) {
            $failedConnection = DB::connection(config('queue.failed.database'));
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');

            if (! $failedConnection->getSchemaBuilder()->hasTable($failedTable)) {
                $this->components->error('The configured database failed-job table does not exist.');

                return self::INVALID;
            }

            $failedCount = $this->processMatches(
                $failedConnection,
                $failedTable,
                fn (string $payload): bool => $this->isPhotoGenerationPayload($payload),
                $delete,
            );
        }

        $verb = $this->option('dry-run') ? 'Found' : 'Purged';

        $this->components->info(sprintf(
            '%s %d legacy pending payload(s) and %d photo-generation failed record(s).',
            $verb,
            $pendingCount,
            $failedCount,
        ));

        return $pendingSupported && $failedSupported ? self::SUCCESS : self::INVALID;
    }

    private function isLegacyPendingPayload(string $payload): bool
    {
        if (! $this->isPhotoGenerationPayload($payload)) {
            return false;
        }

        $command = $this->serializedCommand($payload);

        return str_contains($command, 's:6:"prompt";')
            || str_contains($command, "\0*\0prompt");
    }

    private function isPhotoGenerationPayload(string $payload): bool
    {
        $decoded = json_decode($payload, true);
        $displayName = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;

        return $displayName === GeneratePhotoWithModel::class
            || $displayName === RunPhotoGenerationBatch::class;
    }

    private function serializedCommand(string $payload): string
    {
        $decoded = json_decode($payload, true);

        return is_array($decoded) && is_string($decoded['data']['command'] ?? null)
            ? $decoded['data']['command']
            : '';
    }

    /**
     * @param  callable(string): bool  $matches
     */
    private function processMatches(
        ConnectionInterface $connection,
        string $table,
        callable $matches,
        bool $delete,
    ): int {
        $matchedCount = 0;

        $connection->table($table)
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $records) use ($connection, $table, $matches, $delete, &$matchedCount): void {
                $matchedIds = $records
                    ->filter(fn (object $record): bool => $matches((string) $record->payload))
                    ->pluck('id');

                $matchedCount += $matchedIds->count();

                if ($delete && $matchedIds->isNotEmpty()) {
                    $connection->table($table)->whereIn('id', $matchedIds)->delete();
                }
            }, column: 'id');

        return $matchedCount;
    }
}
