<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $content = $request->getContent();

        if (strlen($content) > (int) config('security.csp_report_max_bytes', 32_768)) {
            return response()->noContent();
        }

        $decoded = json_decode($content, true, depth: 16);
        $report = $this->reportBody(is_array($decoded) ? $decoded : []);
        $directive = $this->directive($report['effective-directive'] ?? $report['violated-directive'] ?? null);

        if ($directive !== null) {
            Log::notice('Content Security Policy violation reported.', [
                'effective_directive' => $directive,
                'blocked_origin' => $this->origin($report['blocked-uri'] ?? null),
                'document_path_sha256' => $this->pathHash($report['document-uri'] ?? null),
                'status_code' => is_numeric($report['status-code'] ?? null)
                    ? (int) $report['status-code']
                    : null,
            ]);
        }

        return response()->noContent();
    }

    /**
     * @param  array<int|string, mixed>  $decoded
     * @return array<string, mixed>
     */
    private function reportBody(array $decoded): array
    {
        $report = array_is_list($decoded) ? ($decoded[0] ?? []) : $decoded;

        if (! is_array($report)) {
            return [];
        }

        $body = $report['csp-report'] ?? $report['body'] ?? $report;

        return is_array($body) ? $body : [];
    }

    private function directive(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $directive = strtolower(trim($value));

        return preg_match('/^[a-z0-9-]{1,80}$/', $directive) === 1 ? $directive : null;
    }

    private function origin(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if (in_array($value, ['inline', 'eval', 'self', 'data', 'blob'], true)) {
            return $value;
        }

        if (strlen($value) > 2048) {
            return 'other';
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)) {
            return 'other';
        }

        $host = strtolower($parts['host']);
        $validHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (! $validHost || strlen($host) > 253) {
            return 'other';
        }

        $origin = $parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        return strlen($origin) <= 275 ? $origin : 'other';
    }

    private function pathHash(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) ? hash('sha256', $path) : null;
    }
}
