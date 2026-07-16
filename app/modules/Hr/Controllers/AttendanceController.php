<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Hr\Services\ActiveBranchService;
use App\Modules\Hr\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Attendance management (Admin + Branch Manager). Sales users cannot edit.
 * A manager is restricted to their own branch; the admin may pick any branch.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendance,
        private ActiveBranchService $activeBranch,
    ) {}

    /** GET /api/hr/attendance?date=YYYY-MM-DD&shop_id=1 */
    public function roster(Request $request)
    {
        $date   = $request->filled('date') ? Carbon::parse($request->date) : Carbon::today();
        $shopId = $this->resolveScopeShopId($request);

        if (! $shopId) {
            return response()->json(['message' => 'يرجى تحديد الفرع', 'data' => []], 422);
        }

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'date'    => $date->toDateString(),
                'shop'    => Shop::find($shopId, ['id', 'name']),
                'roster'  => $this->attendance->roster($shopId, $date),
            ],
        ]);
    }

    /** PUT /api/hr/attendance — mark one employee's status for a day. */
    public function mark(Request $request)
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date'    => ['required', 'date'],
            'status'  => ['required', Rule::in([Attendance::PRESENT, Attendance::LATE, Attendance::ABSENT, Attendance::HALF_DAY])],
            'note'    => ['nullable', 'string'],
        ]);

        $date       = Carbon::parse($data['date']);
        $employee   = User::findOrFail($data['user_id']);
        $empBranch  = $this->activeBranch->activeBranchId($employee, $date);

        // A manager may only mark employees active in their own branch.
        if ($this->isManager()) {
            $managerBranch = $this->managerBranchId();
            if ($empBranch !== $managerBranch) {
                return response()->json(['message' => 'لا يمكنك تسجيل حضور موظف خارج فرعك.'], 403);
            }
        }

        $record = $this->attendance->mark($employee->id, $date, $data['status'], $data['note'] ?? null);

        return response()->json(['message' => 'تم تسجيل الحضور', 'data' => $record]);
    }

    /** GET /api/hr/attendance/mine?year=&month= — the authenticated employee's own history + monthly summary. */
    public function mine(Request $request)
    {
        $year  = $request->integer('year', now()->year);
        $month = $request->integer('month', now()->month);
        $from  = Carbon::create($year, $month, 1)->startOfMonth();
        $to    = $from->copy()->endOfMonth();

        $history = Attendance::where('user_id', $request->user()->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('shop:id,name')
            ->orderByDesc('date')
            ->get(['id', 'date', 'status', 'shop_id', 'note']);

        return response()->json([
            'message' => 'ok',
            'data'    => [
                'year' => $year, 'month' => $month,
                'summary' => $this->attendance->summary($request->user()->id, $from, $to),
                'history' => $history,
            ],
        ]);
    }

    /** Which branch the current request is scoped to. */
    private function resolveScopeShopId(Request $request): ?int
    {
        if ($this->isManager()) {
            return $this->managerBranchId();
        }
        // Admin: pick the requested branch, else the first shop.
        return $request->filled('shop_id') ? (int) $request->shop_id : Shop::min('id');
    }

    private function isManager(): bool
    {
        return auth()->user()->role === 'manager';
    }

    /** The branch a manager manages (managed shop, else their own shop_id). */
    private function managerBranchId(): ?int
    {
        $u = auth()->user();
        $managed = Shop::where('manager_id', $u->id)->value('id');
        return $managed ? (int) $managed : ($u->shop_id ? (int) $u->shop_id : null);
    }
}
