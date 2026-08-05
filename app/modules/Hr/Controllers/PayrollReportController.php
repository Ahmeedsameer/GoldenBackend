<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payroll Report — reuses ReportExportService exactly like SalaryAdvanceReportController
 * (the newest, most idiomatic report pattern in this codebase). One dataset
 * method feeds both the on-screen data() endpoint and export() (PDF/Excel).
 * Every figure is read straight from the already-generated, immutable
 * `payrolls` row — nothing recalculated here.
 */
class PayrollReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    private function reportFilters(Request $request): array
    {
        return [
            'year'    => $request->filled('year') ? (int) $request->get('year') : null,
            'month'   => $request->filled('month') ? (int) $request->get('month') : null,
            'shop_id' => $request->filled('shop_id') ? (int) $request->get('shop_id') : null,
            'user_id' => $request->filled('user_id') ? (int) $request->get('user_id') : null,
            'status'  => $request->get('status'),
        ];
    }

    private function report(array $filters): array
    {
        $query = DB::table('payrolls as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->leftJoin('shops as s', 's.id', '=', 'u.shop_id')
            ->leftJoin('safes as ps', 'ps.id', '=', 'p.paying_safe_id')
            ->leftJoin('shops as pss', 'pss.id', '=', 'ps.shop_id')
            ->select([
                'p.id', 'p.period_year', 'p.period_month', 'p.base_salary', 'p.personal_commission_amount',
                'p.branch_commission_amount', 'p.bonus_total', 'p.penalty_total', 'p.advance_deduction',
                'p.total_deductions', 'p.net_salary', 'p.status', 'p.paid_at',
                'u.id as user_id', 'u.name as user_name',
                's.id as shop_id', 's.name as shop_name',
                'pss.name as paying_safe_shop_name', 'p.paying_safe_id',
            ]);

        if (! empty($filters['year']))    $query->where('p.period_year', $filters['year']);
        if (! empty($filters['month']))   $query->where('p.period_month', $filters['month']);
        if (! empty($filters['shop_id'])) $query->where('u.shop_id', $filters['shop_id']);
        if (! empty($filters['user_id'])) $query->where('p.user_id', $filters['user_id']);
        if (! empty($filters['status']))  $query->where('p.status', $filters['status']);

        return $query->orderByDesc('p.id')->get()->map(fn ($r) => [
            'id'                => $r->id,
            'employee_name'     => $r->user_name,
            'branch_name'       => $r->shop_name ?? '—',
            'period'            => "{$r->period_month}/{$r->period_year}",
            'base_salary'       => round((float) $r->base_salary, 2),
            'commission'        => round((float) $r->personal_commission_amount, 2),
            'branch_profit'     => round((float) $r->branch_commission_amount, 2),
            'bonuses'           => round((float) $r->bonus_total, 2),
            'penalties'         => round((float) $r->penalty_total, 2),
            'advance_deduction' => round((float) $r->advance_deduction, 2),
            'total_deductions'  => round((float) $r->total_deductions, 2),
            'net_salary'        => round((float) $r->net_salary, 2),
            'status'            => $r->status,
            'status_label'      => $r->status === 'paid' ? 'مدفوع' : 'مُولّد',
            'paying_safe_name'  => $r->paying_safe_id ? ($r->paying_safe_shop_name ?? 'الشركة') : '—',
            'paid_at'           => $r->paid_at,
        ])->all();
    }

    // ── GET /hr/reports/payroll ─────────────────────────────────────────────
    public function data(Request $request)
    {
        $filters = $this->reportFilters($request);

        return response()->json(['message' => 'ok', 'data' => $this->report($filters)]);
    }

    // ── GET /hr/reports/payroll/export?format=pdf|excel ────────────────────
    public function export(Request $request)
    {
        $filters = $this->reportFilters($request);
        $rows = $this->report($filters);

        $columns = ['الموظف', 'الفرع', 'الشهر', 'الأساسي', 'العمولة', 'ربح الفرع', 'المكافآت', 'العقوبات', 'قسط السلفة', 'إجمالي الخصومات', 'الصافي', 'الحالة'];
        $tableRows = array_map(fn ($r) => [
            $r['employee_name'], $r['branch_name'], $r['period'], $r['base_salary'], $r['commission'],
            $r['branch_profit'], $r['bonuses'], $r['penalties'], $r['advance_deduction'], $r['total_deductions'],
            $r['net_salary'], $r['status_label'],
        ], $rows);

        $filterLabels = array_filter([
            'السنة' => $filters['year'], 'الشهر' => $filters['month'],
            'الحالة' => $filters['status'] === 'paid' ? 'مدفوع' : ($filters['status'] === 'generated' ? 'مُولّد' : null),
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير الرواتب', $columns, $tableRows, $filterLabels, currencyColumns: [3, 4, 5, 6, 7, 8, 9, 10])
            : $this->exportService->pdf('تقرير الرواتب', $columns, $tableRows, $filterLabels);
    }
}
