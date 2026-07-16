<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;

class EqualizePasswordResetResponseTime
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('password.email')) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $response = $next($request);
        $minimumNanoseconds = max(0, (int) config('fortify.password_reset_min_response_ms', 250)) * 1_000_000;
        $remainingMicroseconds = (int) ceil(($minimumNanoseconds - (hrtime(true) - $startedAt)) / 1_000);

        if ($remainingMicroseconds > 0) {
            Sleep::usleep($remainingMicroseconds)->then(static fn () => null);
        }

        return $response;
    }
}
