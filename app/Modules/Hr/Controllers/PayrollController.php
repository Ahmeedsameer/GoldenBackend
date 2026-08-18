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

    /** Shared filter application for index()/summary() — kept in one place so the dashboard's cards and table always agree. */
    private function filteredQuery(Request $request)
    {
        $query = Payroll::with(['user:id,name,email,shop_id', 'user.shop:id,name']);

        // Managers see only their own branch's employees.
        if ($request->user()->role === 'manager') {
            $branchId = \App\Models\Shop::where('manager_id', $request->user()->id)->value('id') ?? $request->user()->shop_id;
            $query->whereHas('user', fn ($q) => $q->where('shop_id', $branchId));
        } elseif ($request->filled('shop_id')) {
            $query->whereHas('user', fn ($q) => $q->where('shop_id', (int) $request->shop_id));
        }

        foreach (['year' => 'period_year', 'month' => 'period_month', 'user_id' => 'user_id'] as $param => $col) {
            if ($request->filled($param)) {
                $query->where($col, (int) $request->{$param});
            }
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $term = $request->search;
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
        }

        return $query;
    }

    /** GET /api/hr/payrolls?year=&month=&user_id=&shop_id=&status=&search= */
    public function index(Request $request)
    {
        return response()->json(['message' => 'ok', 'data' => $this->filteredQuery($request)->latest()->paginate(30)]);
    }

    /** GET /api/hr/payrolls/summary — same filters as index(), aggregated over the WHOLE filtered set (not just the current page). */
    public function summary(Request $request)
    {
        $rows = $this->filteredQuery($request)->get(['status', 'net_salary']);

        return response()->json(['message' => 'ok', 'data' => [
            'pending_count' => $rows->where('status', Payroll::GENERATED)->count(),
            'paid_count'    => $rows->where('status', Payroll::PAID)->count(),
            'total_amount'  => round((float) $rows->sum('net_salary'), 2),
            'total_paid'    => round((float) $rows->where('status', Payroll::PAID)->sum('net_salary'), 2),
            'remaining'     => round((float) $rows->where('status', Payroll::GENERATED)->sum('net_salary'), 2),
        ]]);
    }

    /** GET /api/hr/payrolls/{id} — full breakdown. */
    public function show(string $id)
    {
        $payroll = Payroll::with([
            'user:id,name,email', 'lines.shop:id,name', 'generatedBy:id,name',
            'payingSafe:id,shop_id,safe_type_id', 'payingSafe.shop:id,name', 'payingSafe.safeType:id,name', 'paidBy:id,name',
        ])->findOrFail($id);

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

    /** @deprecated superseded by pay() below, kept only for backward compatibility. */
    public function markPaid(string $id)
    {
        $p = Payroll::findOrFail($id);
        $this->payroll->markPaid($p);
        $this->audit->log('payroll.paid', $p, ['status' => 'generated'], ['status' => 'paid']);

        return response()->json(['message' => 'تم تعليم الكشف كمدفوع', 'data' => $p]);
    }

    /** PUT /api/hr/payrolls/{id}/pay { safe_id } — admin only; moves real money through Safe. */
    public function pay(Request $request, string $id)
    {
        $p = Payroll::with('user')->findOrFail($id);
        $data = $request->validate(['safe_id' => ['required', 'integer', 'exists:safes,id']]);

        $before = ['status' => $p->status];
        $p = $this->payroll->pay($p, (int) $data['safe_id'], $request->user());
        $this->audit->log('payroll.paid', $p, $before, ['status' => $p->status, 'paying_safe_id' => $p->paying_safe_id, 'net_salary' => $p->net_salary]);

        return response()->json(['message' => 'تم صرف الراتب بنجاح', 'data' => $p]);
    }

    /** POST /api/hr/payrolls/pay-all { safe_id, year?, month?, shop_id?, user_id? } — admin only; bulk-pay every matching PENDING payroll. */
    public function payAll(Request $request)
    {
        $data = $request->validate([
            'safe_id' => ['required', 'integer', 'exists:safes,id'],
            'year'    => ['nullable', 'integer'],
            'month'   => ['nullable', 'integer'],
            'shop_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $filters = collect($data)->only(['year', 'month', 'shop_id', 'user_id'])->filter()->all();
        $result  = $this->payroll->payAll($filters, (int) $data['safe_id'], $request->user());

        $this->audit->log('payroll.paid_all', null, null, ['paid' => count($result['paid']), 'failed' => count($result['failed'])]);

        return response()->json([
            'message' => 'تم صرف ' . count($result['paid']) . ' راتب' . (count($result['failed']) > 0 ? '، وفشل صرف ' . count($result['failed']) : ''),
            'data'    => $result,
        ]);
    }

    /** GET /api/hr/payrolls/{id}/transactions — every SafeTransaction linked to this payroll (salary payment + any advance-installment recovery). */
    public function transactions(string $id)
    {
        $payroll = Payroll::findOrFail($id);
        $advanceId = \App\Models\SalaryAdvanceInstallment::where('payroll_id', $payroll->id)->value('salary_advance_id');

        $rows = \App\Models\SafeTransaction::where('payroll_id', $payroll->id)
            ->when($advanceId, fn ($q) => $q->orWhere(
                fn ($q2) => $q2->where('salary_advance_id', $advanceId)->where('type', 'advance_repayment')
            ))
            ->with(['safe.shop:id,name', 'safe.safeType:id,name', 'user:id,name'])
            ->latest()->get();

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }
}
