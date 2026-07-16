<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AiAuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ExportAiTrustAuditRequest;
use App\Models\AiOperationAudit;
use App\Models\User;
use App\Services\Accounts\AccountMutationGate;
use App\Services\Accounts\CurrentAccount;
use App\Services\Ai\AiAuditLedger;
use App\Services\Ai\AiTrustCenterReadModel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiTrustExportController extends Controller
{
    public function __construct(
        private CurrentAccount $currentAccount,
        private AccountMutationGate $accountMutationGate,
        private AiTrustCenterReadModel $readModel,
        private AiAuditLedger $auditLedger,
    ) {}

    public function __invoke(ExportAiTrustAuditRequest $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $account = $this->currentAccount->resolve($user);
        $filters = $request->validated();

        $cutoff = DB::transaction(function () use ($account, $user, $filters): int {
            $lockedAccount = $this->accountMutationGate->lockManagerOrFail($account->id, $user->id);
            $cutoff = (int) AiOperationAudit::query()
                ->whereBelongsTo($lockedAccount)
                ->max('id');

            $this->auditLedger->append([
                'operation_id' => (string) str()->uuid7(),
                'account_id' => $lockedAccount->id,
                'actor_user_id' => $user->id,
                'event' => AiAuditEvent::AuditExported,
                'provider' => 'trust_center',
                'request_hash' => $this->auditLedger->fingerprint($filters),
                'request_bytes' => count($filters),
            ]);

            return $cutoff;
        }, attempts: 3);

        $chunkSize = min(1000, max(1, (int) config('account-ai.trust_center.audit_export_chunk_size', 250)));
        $filename = 'ai-trust-audit-'.now('UTC')->format('Ymd-His').'Z.csv';

        return response()->streamDownload(function () use ($account, $filters, $cutoff, $chunkSize): void {
            $output = fopen('php://output', 'w');
            abort_unless(is_resource($output), 500);
            fputcsv($output, [
                'timestamp_utc',
                'event',
                'outcome',
                'actor',
                'purpose',
                'provider',
                'model',
                'safe_fingerprint',
                'actual_cost_usd',
                'reserved_cost_usd',
                'operation_id',
            ], ',', '"', '');

            foreach ($this->readModel->exportRows($account, $filters, $cutoff, $chunkSize) as $audit) {
                $actor = $audit->getAttribute('actor_name')
                    ?? ($audit->actor_user_id !== null ? 'Deleted user #'.$audit->actor_user_id : 'System');
                $fingerprint = $audit->credential_fingerprint
                    ?? $audit->request_hash
                    ?? $audit->after_fingerprint
                    ?? $audit->before_fingerprint;
                $actualCost = $audit->event === AiAuditEvent::Succeeded ? $audit->cost_usd : null;
                $reservedCost = $audit->event === AiAuditEvent::Started ? $audit->cost_usd : null;
                $row = [
                    $audit->occurred_at->utc()->format('Y-m-d\TH:i:s\Z'),
                    $audit->event->value,
                    $audit->event->outcome(),
                    $actor,
                    $audit->purpose?->value,
                    $audit->provider,
                    $audit->model,
                    $fingerprint,
                    $actualCost,
                    $reservedCost,
                    $audit->operation_id,
                ];

                fputcsv($output, array_map($this->safeCsvCell(...), $row), ',', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safeCsvCell(mixed $value): string|int|float|null
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return preg_match('/^[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }
}
