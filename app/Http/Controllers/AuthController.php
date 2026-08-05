<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class AuthController extends Controller
{
    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    // public function __construct()
    // {
    //     $this->middleware('auth:api', ['except' => ['login']]);
    // }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login()
    {
        $credentials = request(['email', 'password']);
        $remeber = request('remember')? true : false;
        // return response()->json($credentials);
        if (! $token = auth()->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Deactivated accounts (status = inactive, see users.status) authenticate
        // correctly against their password but must never receive a token —
        // same status flag EmployeeController::toggleStatus() already uses.
        if (auth()->user()->status === 'inactive') {
            auth()->logout();

            return response()->json(['error' => 'هذا الحساب معطّل. يرجى التواصل مع المسؤول.'], 403);
        }

        // Leave Lock — a seller on an approved leave covering today never gets
        // a token, same "authenticate correctly but never issue" shape as the
        // inactive-account check above. Purely date-driven: once the leave's
        // end_date passes, login works again with no admin action needed.
        if (auth()->user()->role === 'sales') {
            $leave = LeaveRequest::where('user_id', auth()->id())
                ->where('status', LeaveRequest::APPROVED)
                ->whereDate('start_date', '<=', today())
                ->whereDate('end_date', '>=', today())
                ->first();

            if ($leave) {
                auth()->logout();

                return response()->json([
                    'error'      => "هذا الموظف في إجازة معتمدة حتى {$leave->end_date->toDateString()}",
                    'error_code' => 'on_leave',
                ], 403);
            }
        }

        return $this->respondWithToken($token);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        return response()->json(auth()->user());
    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth()->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        return $this->respondWithToken(auth()->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'role'=> auth()->user()->role,
            'user' => auth()->user()->only('id', 'name', 'email', 'role', 'shop_id'),
            'expires_in' => auth()->factory()->getTTL() * 60
        ]);
    }
}