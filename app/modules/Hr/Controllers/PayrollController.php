<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Payroll;
use App\Models\User;
use App\Modules\Hr\Services\HrAuditLogger;
use App\Modules\Hr\Services\PayrollService;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function __construct(
        private PayrollService $payroll,
        private HrAuditLogger $audit,
    ) {}

    /** GET /api/hr/payrolls?year=&month=&user_id= */
    public function index(Request $request)
    {
        $query = Payroll::with('user:id,name,email');

        // Managers see only their own branch's employees.
        if ($request->user()->role === 'manager') {
            $branchId = \App\Models\Shop::where('manager_id', $request->user()->id)->value('id') ?? $request->user()->shop_id;
            $query->whereHas('user', fn ($q) => $q->where('shop_id', $branchId));
        }
        foreach (['year' => 'period_year', 'month' => 'period_month', 'user_id' => 'user_id'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($col, (int) $request->{$param});
            }
        }

        return response()->json(['message' => 'ok', 'data' => $query->latest()->paginate(30)]);
    }

    /** GET /api/hr/payrolls/{id} — full breakdown. */
    public function show(string $id)
    {
        $payroll = Payroll::with(['user:id,name,email', 'lines.shop:id,name', 'generatedBy:id,name'])->findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $payroll]);
    }

    /** POST /api/hr/payrolls/generate {year, month, user_id?} — admin only. */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'year'    => ['required', 'integer', 'min:2020', 'max:2100'],
            'month'   => ['required', 'integer', 'min:1', 'max:12'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($data['user_id'])) {
            $employee = User::findOrFail($data['user_id']);
            $result   = $this->payroll->generate($employee, $data['year'], $data['month']);
            $this->audit->log('payroll.generated', $result, null, ['year' => $data['year'], 'month' => $data['month'], 'net' => $result->net_salary]);

            return response()->json(['message' => 'تم توليد كشف الراتب', 'data' => $result->load('lines')]);
        }

        $summary = $this->payroll->generateAll($data['year'], $data['month']);
        $this->audit->log('payroll.generated_all', null, null, $summary);

        return response()->json(['message' => "تم توليد كشوف رواتب {$summary['generated']} موظف", 'data' => $summary]);
    }

    public function lock(string $id)
    {
        $p = Payroll::findOrFail($id);
        $this->payroll->lock($p);
        $this->audit->log('payroll.locked', $p, ['is_locked' => false], ['is_locked' => true]);

        return response()->json(['message' => 'تم قفل كشف الراتب', 'data' => $p]);
    }

    public function unlock(string $id)
    {
        $p = Payroll::findOrFail($id);
        $this->payroll->unlock($p);
        $this->audit->log('payroll.unlocked', $p, ['is_locked' => true], ['is_locked' => false]);

        return response()->json(['message' => 'تم فتح قفل كشف الراتب', 'data' => $p]);
    }

    public function markPaid(string $id)
    {
        $p = Payroll::findOrFail($id);
        $this->payroll->markPaid($p);
        $this->audit->log('payroll.paid', $p, ['status' => 'generated'], ['status' => 'paid']);

        return response()->json(['message' => 'تم تعليم الكشف كمدفوع', 'data' => $p]);
    }
}
