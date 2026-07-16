<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Accounts\CurrentAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentAccount
{
    public function __construct(private CurrentAccount $currentAccount) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->currentAccount->resolve($user);
        }

        return $next($request);
    }
}
