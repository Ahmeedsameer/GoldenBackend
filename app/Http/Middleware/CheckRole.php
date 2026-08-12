<?php

namespace App\Http\Middleware;

use App\Models\LeaveRequest;
use App\Modules\Hr\Services\ShiftAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function __construct(private ShiftAccessService $shiftAccess) {}

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
            if ($leaveMessage = LeaveRequest::leaveMessageFor(auth()->id())) {
                return response()->json([
                    'error'      => $leaveMessage,
                    'error_code' => 'on_leave',
                ], 403);
            }
        }

        // Manager Shift Lock — a manager outside their shift (and outside any
        // approved overtime window) loses access to every WORK route mid-
        // session, not just selling. `:*` (wildcard) routes are exempt on
        // purpose — that's exactly the self-service/"view my own data" +
        // leave-request surface (hr/me/*, */mine, leaves, leaves/{id}/cancel)
        // the manager must keep regardless of shift status. Every other
        // group a manager can reach (stock, pricing, branch-operations,
        // sales/cashier, hr admin review, …) declares specific roles, so it
        // is a WORK route and gets locked here. Sales already has its own
        // narrower lock (selling only, via SalesService::createInvoice())
        // since selling is the only real action a sales employee has.
        if (auth()->user()->role === 'manager' && ! in_array('*', $roles, true)) {
            if ($shiftMessage = $this->shiftAccess->blockMessageFor(auth()->id())) {
                return response()->json([
                    'error'      => $shiftMessage,
                    'error_code' => 'off_shift',
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
