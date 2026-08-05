<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Salary Payment Report — every `salary_payment` SafeTransaction (real cash
 * that actually left a Safe), reusing ReportExportService like every other
 * report. Distinct from the Payroll Report: a payroll can exist without
 * being paid yet, this report only ever shows money that has actually moved.
 */
class SalaryPaymentReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        return [now()->subDays(30)->toDateString(), now()->toDateString()];
    }

    private function reportFilters(Request $request): array
    {
        [$from, $to] = $this->parseDates($request);

        return [
            'from' => $from, 'to' => $to,
            'safe_id' => $request->filled('safe_id') ? (int) $request->get('safe_id') : null,
            'user_id' => $request->filled('user_id') ? (int) $request->get('user_id') : null,
        ];
    }

    private function report(array $filters): array
    {
        $query = DB::table('safe_transactions as t')
            ->join('payrolls as p', 'p.id', '=', 't.payroll_id')
            ->join('users as emp', 'emp.id', '=', 'p.user_id')
            ->join('safes as sf', 'sf.id', '=', 't.safe_id')
            ->leftJoin('shops as sh', 'sh.id', '=', 'sf.shop_id')
            ->leftJoin('users as payer', 'payer.id', '=', 't.user_id')
            ->where('t.type', 'salary_payment')
            ->whereBetween(DB::raw('DATE(t.created_at)'), [$filters['from'], $filters['to']])
            ->select([
                't.id', 't.amount', 't.created_at',
                'p.id as payroll_id', 'p.period_year', 'p.period_month',
                'emp.name as employee_name',
                'sf.id as safe_id', 'sh.name as safe_shop_name',
                'payer.name as paid_by_name',
            ]);

        if (! empty($filters['safe_id'])) $query->where('t.safe_id', $filters['safe_id']);
        if (! empty($filters['user_id'])) $query->where('p.user_id', $filters['user_id']);

        return $query->orderByDesc('t.id')->get()->map(fn ($r) => [
            'id'             => $r->id,
            'employee_name'  => $r->employee_name,
            'period'         => "{$r->period_month}/{$r->period_year}",
            'safe_name'      => $r->safe_shop_name ?? 'الشركة',
            'amount'         => round((float) $r->amount, 2),
            'paid_by'        => $r->paid_by_name ?? '—',
            'paid_at'        => (string) $r->created_at,
            'payroll_id'     => $r->payroll_id,
        ])->all();
    }

    // ── GET /hr/reports/salary-payments ─────────────────────────────────────
    public function data(Request $request)
    {
        $filters = $this->reportFilters($request);

        return response()->json(['message' => 'ok', 'data' => $this->report($filters), 'period' => ['from' => $filters['from'], 'to' => $filters['to']]]);
    }

    // ── GET /hr/reports/salary-payments/export?format=pdf|excel ────────────
    public function export(Request $request)
    {
        $filters = $this->reportFilters($request);
        $rows = $this->report($filters);

        $columns = ['الموظف', 'الشهر', 'الخزنة', 'المبلغ', 'صرفه', 'تاريخ الصرف'];
        $tableRows = array_map(fn ($r) => [
            $r['employee_name'], $r['period'], $r['safe_name'], $r['amount'], $r['paid_by'], $r['paid_at'],
        ], $rows);

        $filterLabels = array_filter(['من' => $filters['from'], 'إلى' => $filters['to']], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير صرف الرواتب', $columns, $tableRows, $filterLabels, currencyColumns: [3])
            : $this->exportService->pdf('تقرير صرف الرواتب', $columns, $tableRows, $filterLabels);
    }
}
