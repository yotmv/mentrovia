<?php

use Illuminate\Support\Facades\Log;

test('csp reports are accepted without authentication and logged without sensitive urls', function () {
    Log::spy();

    $payload = (string) json_encode([
        'csp-report' => [
            'document-uri' => 'https://mentrovia.example/dashboard?private=company-data',
            'blocked-uri' => 'https://evil.example/script.js?secret=token',
            'effective-directive' => 'script-src-elem',
            'status-code' => 200,
        ],
    ]);

    $this->call(
        'POST',
        route('csp-reports'),
        server: ['CONTENT_TYPE' => 'application/csp-report'],
        content: $payload,
    )->assertNoContent();

    Log::shouldHaveReceived('notice')
        ->once()
        ->with('Content Security Policy violation reported.', [
            'effective_directive' => 'script-src-elem',
            'blocked_origin' => 'https://evil.example',
            'document_path_sha256' => hash('sha256', '/dashboard'),
            'status_code' => 200,
        ]);
});

test('invalid csp reports are discarded without logging attacker-controlled values', function () {
    Log::spy();

    $this->postJson(route('csp-reports'), [
        'csp-report' => [
            'effective-directive' => "script-src\nforged-log-entry",
            'blocked-uri' => 'file:///etc/passwd',
        ],
    ])->assertNoContent();

    Log::shouldNotHaveReceived('notice');
});

test('csp telemetry bounds request bodies and attacker-controlled origins', function () {
    Log::spy();
    config(['security.csp_report_max_bytes' => 128]);

    $oversizedPayload = (string) json_encode([
        'csp-report' => [
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://'.str_repeat('a', 256).'.example/script.js',
        ],
    ]);

    $this->call(
        'POST',
        route('csp-reports'),
        server: ['CONTENT_TYPE' => 'application/csp-report'],
        content: $oversizedPayload,
    )->assertNoContent();

    Log::shouldNotHaveReceived('notice');

    config(['security.csp_report_max_bytes' => 32_768]);

    $malformedOriginPayload = (string) json_encode([
        'csp-report' => [
            'effective-directive' => 'script-src',
            'blocked-uri' => 'https://'.str_repeat('a', 254),
        ],
    ]);

    $this->call(
        'POST',
        route('csp-reports'),
        server: ['CONTENT_TYPE' => 'application/csp-report'],
        content: $malformedOriginPayload,
    )->assertNoContent();

    Log::shouldHaveReceived('notice')
        ->once()
        ->with('Content Security Policy violation reported.', [
            'effective_directive' => 'script-src',
            'blocked_origin' => 'other',
            'document_path_sha256' => null,
            'status_code' => null,
        ]);
});
