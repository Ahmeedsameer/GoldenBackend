<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EmployeeTransfer;
use App\Models\Payroll;
use App\Models\Shop;
use App\Modules\Hr\Services\ActiveBranchService;
use App\Modules\Hr\Services\AttendanceService;
use App\Modules\Hr\Services\CommissionService;
use App\Modules\Hr\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Employee self-service — the data behind the Sales / Manager personal
 * dashboard. Each employee sees ONLY their own information.
 */
class SelfServiceController extends Controller
{
    public function __construct(
        private CommissionService $commissions,
        private AttendanceService $attendance,
        private LeaveService $leaves,
        private ActiveBranchService $activeBranch,
    ) {}

    /** GET /api/hr/me/summary */
    public function summary(Request $request)
    {
        $me   = $request->user();
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::now()->endOfMonth();

        $personal = $this->commissions->personalCommission($me, $from, $to);
        $branch   = $this->commissions->branchCommission($me, $from, $to);
        $estimated = round((float) $me->base_salary + $personal['amount'] + $branch['total'], 2);

        $activeBranchId = $this->activeBranch->activeBranchId($me);

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'employee' => [
                    'id'          => $me->id,
                    'name'        => $me->name,
                    'email'       => $me->email,
                    'role'        => $me->role,
                    'hire_date'   => $me->hire_date,
                    'base_salary' => $me->base_salary,
                ],
                'active_branch'  => $activeBranchId ? Shop::find($activeBranchId, ['id', 'name']) : null,
                'is_transferred' => $activeBranchId !== ($me->shop_id ? (int) $me->shop_id : null),
                'period'         => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'commission'     => [
                    'personal'         => $personal,
                    'branch'           => $branch,
                    'estimated_salary' => $estimated,
                ],
                'attendance'     => $this->attendance->summary($me->id, $from, $to),
                'leave_balance'  => $this->leaves->balance($me),
                'recent_payrolls'=> Payroll::where('user_id', $me->id)->latest()->take(6)
                    ->get(['id', 'period_year', 'period_month', 'net_salary', 'status', 'is_locked']),
                'transfers'      => EmployeeTransfer::where('user_id', $me->id)
                    ->whereIn('status', ['scheduled', 'active'])
                    ->with('temporaryBranch:id,name')
                    ->get(['id', 'temporary_branch_id', 'start_date', 'end_date', 'status']),
            ],
        ]);
    }
}
