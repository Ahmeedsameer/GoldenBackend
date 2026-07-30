<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Wildcard: any authenticated user may pass.
        if (in_array('*', $roles, true) && auth()->check()) {
            return $next($request);
        }

        // Guest routes: only pass when there is no authenticated user.
        if (in_array('guest', $roles, true) && auth()->guest()) {
            return $next($request);
        }

        // No valid authenticated user at all (no token / invalid token / expired
        // token — auth()->check() collapses all three into false, see JWTGuard)
        // — this is a 401, distinct from "authenticated but wrong role" below.
        // Does not change any access decision, only which status code an
        // already-blocked request receives.
        if (! auth()->check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Otherwise the user's role must be one of the allowed roles.
        if (in_array(auth()->user()->role, $roles, true)) {
            return $next($request);
        }

        return response()->json(['error' => 'Unauthorized'], 403);
    }
}
