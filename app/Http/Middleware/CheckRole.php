<?php

namespace App\Http\Middleware;

use App\Models\LeaveRequest;
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

        // A deactivated account (users.status = inactive) loses access immediately,
        // even mid-session with an already-issued token — the login check alone
        // wouldn't catch this until the token expires/refreshes.
        if (auth()->user()->status === 'inactive') {
            auth()->logout();

            return response()->json(['error' => 'هذا الحساب معطّل. يرجى التواصل مع المسؤول.'], 403);
        }

        // Leave Lock — a seller on an approved leave covering today loses
        // access to every role-gated route mid-session (cashier, invoices,
        // sales operations — every one of them sits behind CheckRole), the
        // same "even with an already-issued token" reach the inactive-status
        // check above has. Purely date-driven, so access resumes automatically
        // once the leave's end_date passes — nothing to manually re-enable.
        if (auth()->user()->role === 'sales') {
            $leave = LeaveRequest::where('user_id', auth()->id())
                ->where('status', LeaveRequest::APPROVED)
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today())
                ->first();

            if ($leave) {
                return response()->json([
                    'error'      => "هذا الموظف في إجازة معتمدة حتى {$leave->end_date->toDateString()}",
                    'error_code' => 'on_leave',
                ], 403);
            }
        }

        // Otherwise the user's role must be one of the allowed roles.
        if (in_array(auth()->user()->role, $roles, true)) {
            return $next($request);
        }

        return response()->json(['error' => 'Unauthorized'], 403);
    }
}
