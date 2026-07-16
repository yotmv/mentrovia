<?php

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

test('application responses carry browser security headers', function () {
    $response = $this->get('/');

    $response
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
        ->assertHeader(
            'Content-Security-Policy',
            "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
        );

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self' 'nonce-")
        ->toContain("connect-src 'self'")
        ->toContain('report-uri /csp-reports')
        ->toContain('report-to csp-endpoint');

    expect($response->headers->get('Reporting-Endpoints'))
        ->toBe('csp-endpoint="'.route('csp-reports').'"');

    $response
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('hsts is emitted only for secure requests when explicitly enabled', function () {
    config(['security.hsts_enabled' => true]);

    $this->get('https://localhost/')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('trusted forwarded https requests receive hsts', function () {
    config(['security.hsts_enabled' => true]);

    $this->withServerVariables([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '443',
    ])->get('http://localhost/')
        ->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('rendered error responses carry security headers', function (string $path, int $status) {
    if ($status === 500) {
        Exceptions::fake();
        Route::get($path, static fn () => throw new RuntimeException('Synthetic test failure'));
    }

    $this->get($path)
        ->assertStatus($status)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
})->with([
    'not found' => ['/security-header-missing-page', 404],
    'server error' => ['/security-header-server-error', 500],
]);
