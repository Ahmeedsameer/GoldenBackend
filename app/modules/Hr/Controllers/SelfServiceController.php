<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Bonus;
use App\Models\EmployeeTransfer;
use App\Models\HrAuditLog;
use App\Models\Invoice;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\Payroll;
use App\Models\Penalty;
use App\Models\Shop;
use App\Models\User;
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

    /** GET /api/hr/me/profile — the full personal profile page. */
    public function profile(Request $request)
    {
        $me   = $request->user();
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::now()->endOfMonth();

        $personal = $this->commissions->personalCommission($me, $from, $to);
        $branch   = $this->commissions->branchCommission($me, $from, $to);
        $activeBranchId = $this->activeBranch->activeBranchId($me);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'employee' => [
                    'id'            => $me->id,
                    'employee_code' => 'EMP-' . str_pad((string) $me->id, 4, '0', STR_PAD_LEFT),
                    'name'          => $me->name,
                    'email'         => $me->email,
                    'phone'         => $me->phone,
                    'role'          => $me->role,
                    'status'        => $me->status,
                    'hire_date'     => $me->hire_date,
                    'base_salary'   => $me->base_salary,
                    'personal_commission_percent' => $me->personal_commission_percent,
                    'monthly_leave_allowance'     => $me->monthly_leave_allowance,
                ],
                'primary_branch' => $me->primaryBranch()->first(['id', 'name']),
                'active_branch'  => $activeBranchId ? Shop::find($activeBranchId, ['id', 'name']) : null,
                'is_transferred' => $activeBranchId !== ($me->shop_id ? (int) $me->shop_id : null),
                'leave_balance'  => $this->leaves->balance($me),
                'attendance_summary' => $this->attendance->summary($me->id, $from, $to),
                'sales_summary'  => ['sales_total' => $personal['sales_total'], 'commission' => $personal['amount']],
                'branch_bonus'   => $branch['total'],
                'payroll_history' => Payroll::where('user_id', $me->id)->latest()->take(12)
                    ->get(['id', 'period_year', 'period_month', 'net_salary', 'status', 'is_locked']),
                'transfer_history' => EmployeeTransfer::where('user_id', $me->id)
                    ->with('temporaryBranch:id,name')
                    ->latest()
                    ->take(20)
                    ->get(['id', 'temporary_branch_id', 'start_date', 'end_date', 'status', 'reason']),
                'recent_notifications' => Notification::where('user_id', $me->id)
                    ->latest()->take(10)->get(['id', 'type', 'title', 'message', 'read_at', 'created_at']),
            ],
        ]);
    }

    /** GET /api/hr/me/sales?year=&month= — personal sales/commission page. */
    public function sales(Request $request)
    {
        $me    = $request->user();
        $year  = $request->integer('year', now()->year);
        $month = $request->integer('month', now()->month);
        $from  = Carbon::create($year, $month, 1)->startOfMonth();
        $to    = $from->copy()->endOfMonth();

        $base = Invoice::where('seller_id', $me->id)->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as c, COALESCE(SUM(total_amount),0) as total')
            ->groupBy('status')->get()->keyBy('status');

        $personal = $this->commissions->personalCommission($me, $from, $to);
        $branch   = $this->commissions->branchCommission($me, $from, $to);
        $estimated = round((float) $me->base_salary + $personal['amount'] + $branch['total'], 2);

        $approvedCount = (int) ($byStatus['approved']->c ?? 0);
        $approvedTotal = (float) ($byStatus['approved']->total ?? 0);

        $bonusTotal   = round((float) Bonus::where('user_id', $me->id)->where('status', Bonus::ACTIVE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2);
        $penaltyTotal = round((float) Penalty::where('user_id', $me->id)->where('status', Penalty::ACTIVE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('amount'), 2);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['year' => $year, 'month' => $month, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
                'invoices' => [
                    'approved' => ['count' => $approvedCount, 'total' => round($approvedTotal, 2)],
                    'pending'  => ['count' => (int) ($byStatus['pending']->c ?? 0),   'total' => round((float) ($byStatus['pending']->total ?? 0), 2)],
                    // "Rejected" in the UI maps to the invoice's actual 'cancelled' status.
                    'rejected' => ['count' => (int) ($byStatus['cancelled']->c ?? 0), 'total' => round((float) ($byStatus['cancelled']->total ?? 0), 2)],
                ],
                'personal_commission' => $personal,
                'branch_bonus'        => $branch,
                'bonus_total'         => $bonusTotal,
                'penalty_total'       => $penaltyTotal,
                'estimated_salary'    => round($estimated + $bonusTotal - $penaltyTotal, 2),
                'monthly_performance' => [
                    'invoices_count'      => $approvedCount,
                    'total_sales'         => round($approvedTotal, 2),
                    'average_invoice'     => $approvedCount > 0 ? round($approvedTotal / $approvedCount, 2) : 0,
                ],
            ],
        ]);
    }

    /** GET /api/hr/me/timeline — own chronological employment history. */
    public function timeline(Request $request)
    {
        return response()->json(['message' => 'ok', 'data' => $this->buildTimeline($request->user()->id)]);
    }

    /** GET /api/hr/employees/{id}/timeline — admin viewing any employee's history. */
    public function timelineFor(string $id)
    {
        $employee = User::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->buildTimeline($employee->id)]);
    }

    /**
     * Chronological Employment Timeline: every HrAuditLog entry whose subject
     * belongs to this employee, across all the entity types the audit log
     * already covers (User, LeaveRequest, Bonus, Penalty, Payroll,
     * EmployeeTransfer) — no new logging system, just a per-employee view
     * over the existing polymorphic audit trail.
     */
    private function buildTimeline(int $userId, int $limit = 100)
    {
        $entries = collect();

        $entries = $entries->merge(HrAuditLog::where('subject_type', User::class)->where('subject_id', $userId)->get());

        $map = [
            LeaveRequest::class     => LeaveRequest::where('user_id', $userId)->pluck('id'),
            Bonus::class             => Bonus::where('user_id', $userId)->pluck('id'),
            Penalty::class           => Penalty::where('user_id', $userId)->pluck('id'),
            Payroll::class           => Payroll::where('user_id', $userId)->pluck('id'),
            EmployeeTransfer::class  => EmployeeTransfer::where('user_id', $userId)->pluck('id'),
        ];

        foreach ($map as $subjectClass => $ids) {
            if ($ids->isNotEmpty()) {
                $entries = $entries->merge(HrAuditLog::where('subject_type', $subjectClass)->whereIn('subject_id', $ids)->get());
            }
        }

        return $entries->sortByDesc('created_at')->take($limit)->values()
            ->map(fn (HrAuditLog $e) => [
                'id'         => $e->id,
                'action'     => $e->action,
                'old_values' => $e->old_values,
                'new_values' => $e->new_values,
                'created_at' => $e->created_at,
            ]);
    }
}
