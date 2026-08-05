<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Advance Installments Report — per-installment detail (the Advances Report
 * covers whole advances only). Reuses ReportExportService like every other
 * report; reads salary_advance_installments as-is, nothing recalculated.
 */
class AdvanceInstallmentReportController extends Controller
{
    private const STATUS_LABELS = ['pending' => 'قيد الانتظار', 'due' => 'مستحق', 'paid' => 'مدفوع', 'skipped' => 'متجاوز', 'cancelled' => 'ملغى'];

    public function __construct(private ReportExportService $exportService) {}

    private function reportFilters(Request $request): array
    {
        return [
            'year'    => $request->filled('year') ? (int) $request->get('year') : null,
            'month'   => $request->filled('month') ? (int) $request->get('month') : null,
            'user_id' => $request->filled('user_id') ? (int) $request->get('user_id') : null,
            'status'  => $request->get('status'),
        ];
    }

    private function report(array $filters): array
    {
        $now = now();
        $query = DB::table('salary_advance_installments as i')
            ->join('salary_advances as a', 'a.id', '=', 'i.salary_advance_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('payrolls as p', 'p.id', '=', 'i.payroll_id')
            ->select([
                'i.id', 'i.period_year', 'i.period_month', 'i.sequence', 'i.planned_amount', 'i.status', 'i.deducted_at',
                'a.id as advance_id', 'u.name as employee_name', 'p.id as payroll_id',
            ]);

        if (! empty($filters['year']))    $query->where('i.period_year', $filters['year']);
        if (! empty($filters['month']))   $query->where('i.period_month', $filters['month']);
        if (! empty($filters['user_id'])) $query->where('a.user_id', $filters['user_id']);

        $rows = $query->orderByDesc('i.period_year')->orderByDesc('i.period_month')->get()->map(function ($r) use ($now) {
            $effectiveStatus = $r->status;
            if ($r->status === 'pending' && (int) $r->period_year === (int) $now->year && (int) $r->period_month === (int) $now->month) {
                $effectiveStatus = 'due';
            }

            return [
                'id'               => $r->id,
                'employee_name'    => $r->employee_name,
                'advance_id'       => $r->advance_id,
                'period'           => "{$r->period_month}/{$r->period_year}",
                'sequence'         => $r->sequence,
                'amount'           => round((float) $r->planned_amount, 2),
                'status'           => $effectiveStatus,
                'status_label'     => self::STATUS_LABELS[$effectiveStatus] ?? $effectiveStatus,
                'deducted_at'      => $r->deducted_at,
                'payroll_id'       => $r->payroll_id,
            ];
        });

        if (! empty($filters['status'])) {
            $rows = $rows->filter(fn ($r) => $r['status'] === $filters['status']);
        }

        return $rows->values()->all();
    }

    // ── GET /hr/reports/advance-installments ────────────────────────────────
    public function data(Request $request)
    {
        $filters = $this->reportFilters($request);

        return response()->json(['message' => 'ok', 'data' => $this->report($filters)]);
    }

    // ── GET /hr/reports/advance-installments/export?format=pdf|excel ───────
    public function export(Request $request)
    {
        $filters = $this->reportFilters($request);
        $rows = $this->report($filters);

        $columns = ['الموظف', 'رقم السلفة', 'الشهر', 'رقم القسط', 'المبلغ', 'الحالة', 'تاريخ الخصم'];
        $tableRows = array_map(fn ($r) => [
            $r['employee_name'], $r['advance_id'], $r['period'], $r['sequence'], $r['amount'], $r['status_label'], $r['deducted_at'] ?? '—',
        ], $rows);

        $filterLabels = array_filter([
            'السنة' => $filters['year'], 'الشهر' => $filters['month'],
            'الحالة' => $filters['status'] ? (self::STATUS_LABELS[$filters['status']] ?? $filters['status']) : null,
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير أقساط السلف', $columns, $tableRows, $filterLabels, currencyColumns: [4])
            : $this->exportService->pdf('تقرير أقساط السلف', $columns, $tableRows, $filterLabels);
    }
}
