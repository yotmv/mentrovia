<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('security.csp_report_only_enabled', true)) {
            Vite::useCspNonce();
        }

        return $this->apply($request, $next($request));
    }

    public function apply(Request $request, Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            "base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'",
        );

        if (config('security.csp_report_only_enabled', true)) {
            $nonce = Vite::cspNonce() ?? Vite::useCspNonce();

            $response->headers->set(
                'Content-Security-Policy-Report-Only',
                "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; report-uri /csp-reports; report-to csp-endpoint",
            );
            $response->headers->set(
                'Reporting-Endpoints',
                'csp-endpoint="'.$request->getSchemeAndHttpHost().'/csp-reports"',
            );
        }

        if ($request->isSecure() && config('security.hsts_enabled', false)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
