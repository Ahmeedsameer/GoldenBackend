<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Leave Deductions Report — reads the `payroll_lines` rows PayrollService's
 * computeComponents() already wrote for unpaid-leave deductions (meta.code =
 * 'unpaid_leave'; see PayrollService), never recomputes the daily-rate math
 * itself. Absence/late/half-day deductions are a separate category (already
 * covered by the Payroll Report's total_deductions) — this report is
 * specifically the Leave-driven portion.
 */
class LeaveDeductionReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function reportFilters(Request $request): array
    {
        return [
            'year'    => $request->filled('year') ? (int) $request->get('year') : null,
            'month'   => $request->filled('month') ? (int) $request->get('month') : null,
            'user_id' => $request->filled('user_id') ? (int) $request->get('user_id') : null,
        ];
    }

    private function report(array $filters): array
    {
        $query = DB::table('payroll_lines as l')
            ->join('payrolls as p', 'p.id', '=', 'l.payroll_id')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('l.type', 'deduction')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(l.meta, '$.code')) = 'unpaid_leave'")
            ->select([
                'l.id', 'l.amount', 'l.meta',
                'p.id as payroll_id', 'p.period_year', 'p.period_month', 'p.unpaid_leave_days',
                'u.id as user_id', 'u.name as employee_name',
            ]);

        if (! empty($filters['year']))    $query->where('p.period_year', $filters['year']);
        if (! empty($filters['month']))   $query->where('p.period_month', $filters['month']);
        if (! empty($filters['user_id'])) $query->where('p.user_id', $filters['user_id']);

        return $query->orderByDesc('p.period_year')->orderByDesc('p.period_month')->get()->map(function ($r) {
            $meta = json_decode($r->meta, true) ?? [];

            return [
                'id'              => $r->id,
                'employee_name'   => $r->employee_name,
                'period'          => "{$r->period_month}/{$r->period_year}",
                'unpaid_days'     => (int) $r->unpaid_leave_days,
                'per_day'         => round((float) ($meta['per_unit'] ?? 0), 2),
                'amount'          => round(abs((float) $r->amount), 2),
                'payroll_id'      => $r->payroll_id,
            ];
        })->all();
    }

    // ── GET /hr/reports/leave-deductions ────────────────────────────────────
    public function data(Request $request)
    {
        $filters = $this->reportFilters($request);

        return response()->json(['message' => 'ok', 'data' => $this->report($filters)]);
    }

    // ── GET /hr/reports/leave-deductions/export?format=pdf|excel ───────────
    public function export(Request $request)
    {
        $filters = $this->reportFilters($request);
        $rows = $this->report($filters);

        $columns = ['الموظف', 'الشهر', 'أيام الإجازة بدون أجر', 'قيمة اليوم', 'إجمالي الخصم'];
        $tableRows = array_map(fn ($r) => [
            $r['employee_name'], $r['period'], $r['unpaid_days'], $r['per_day'], $r['amount'],
        ], $rows);

        $filterLabels = array_filter(['السنة' => $filters['year'], 'الشهر' => $filters['month']], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير خصومات الإجازات', $columns, $tableRows, $filterLabels, currencyColumns: [3, 4])
            : $this->exportService->pdf('تقرير خصومات الإجازات', $columns, $tableRows, $filterLabels);
    }
}
