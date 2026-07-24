<?php

namespace App\Modules\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6.3 — dedicated Salary Advance report. Reuses ReportExportService
 * exactly like every other report in this codebase (Transfer Invoices, Waste,
 * Adjustments, …) — no separate reporting engine. One dataset method feeds
 * both the on-screen data() endpoint and export() (PDF/Excel), so the numbers
 * a user sees on screen are exactly what gets exported.
 */
class SalaryAdvanceReportController extends Controller
{
    private const STATUS_LABELS = [
        'pending' => 'قيد المراجعة', 'active' => 'نشطة', 'rejected' => 'مرفوضة',
        'completed' => 'مكتملة', 'cancelled' => 'ملغاة',
    ];

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
            'user_id' => $request->filled('user_id') ? (int) $request->get('user_id') : null,
            'shop_id' => $request->filled('shop_id') ? (int) $request->get('shop_id') : null,
            'paying_safe_id' => $request->filled('paying_safe_id') ? (int) $request->get('paying_safe_id') : null,
            'receiving_safe_id' => $request->filled('receiving_safe_id') ? (int) $request->get('receiving_safe_id') : null,
            'status' => $request->get('status'),
            'creator_id' => $request->filled('creator_id') ? (int) $request->get('creator_id') : null,
            'search' => $request->get('search'),
        ];
    }

    /**
     * One row per advance. "Date" is the approval/rejection moment
     * (reviewed_at) — falling back to request_date for still-pending advances
     * (no money has moved for those yet, but they still belong in the range
     * they were requested in). "Receiving Safe" can legitimately be more than
     * one (several early repayments to different safes over time), so it's
     * aggregated as a distinct list per advance — filtered in PHP afterward
     * exactly like TransferReportController does for its own derived filters.
     */
    private function report(array $filters): array
    {
        $query = DB::table('salary_advances as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('shops as s', 's.id', '=', 'u.shop_id')
            ->leftJoin('safes as ps', 'ps.id', '=', 'a.paying_safe_id')
            ->leftJoin('shops as pss', 'pss.id', '=', 'ps.shop_id')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'a.reviewed_by')
            ->selectRaw('
                a.id, a.requested_amount, a.approved_amount, a.paid_amount, a.status,
                a.request_date, a.reviewed_at,
                u.id as user_id, u.name as user_name, u.email as user_email,
                s.id as shop_id, s.name as shop_name,
                a.paying_safe_id, pss.name as paying_safe_shop_name,
                reviewer.id as reviewer_id, reviewer.name as reviewer_name
            ')
            ->whereRaw('COALESCE(a.reviewed_at, a.request_date) BETWEEN ? AND ?', [$filters['from'], $filters['to'] . ' 23:59:59']);

        if (! empty($filters['user_id'])) {
            $query->where('a.user_id', $filters['user_id']);
        }
        if (! empty($filters['shop_id'])) {
            $query->where('u.shop_id', $filters['shop_id']);
        }
        if (! empty($filters['paying_safe_id'])) {
            $query->where('a.paying_safe_id', $filters['paying_safe_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('a.status', $filters['status']);
        }
        if (! empty($filters['creator_id'])) {
            $query->where('a.reviewed_by', $filters['creator_id']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(fn ($q) => $q->where('u.name', 'like', "%{$term}%")->orWhere('u.email', 'like', "%{$term}%"));
        }

        $rows = $query->orderByDesc('a.id')->get();

        $advanceIds = $rows->pluck('id')->all();
        $receivingSafes = DB::table('salary_advance_repayments as r')
            ->join('safes as sf', 'sf.id', '=', 'r.safe_id')
            ->leftJoin('shops as rsh', 'rsh.id', '=', 'sf.shop_id')
            ->whereIn('r.salary_advance_id', $advanceIds)
            ->select('r.salary_advance_id', 'r.safe_id', 'rsh.name as shop_name')
            ->get()
            ->groupBy('salary_advance_id');

        $mapped = $rows->map(function ($r) use ($receivingSafes) {
            $safesForAdvance = $receivingSafes->get($r->id, collect());
            $receivingSafeIds = $safesForAdvance->pluck('safe_id')->unique()->values()->all();
            $receivingSafeNames = $safesForAdvance->pluck('shop_name')->map(fn ($n) => $n ?? 'الشركة')->unique()->values()->all();

            return [
                'id' => $r->id,
                'employee_name' => $r->user_name,
                'employee_email' => $r->user_email,
                'branch_name' => $r->shop_name ?? '—',
                'paying_safe_name' => $r->paying_safe_id ? ($r->paying_safe_shop_name ?? 'الشركة') : '—',
                'receiving_safe_names' => $receivingSafeNames,
                'receiving_safe_ids' => $receivingSafeIds,
                'status' => $r->status,
                'status_label' => self::STATUS_LABELS[$r->status] ?? $r->status,
                'amount' => round((float) ($r->approved_amount ?? $r->requested_amount), 2),
                'remaining_balance' => $r->status === 'active'
                    ? round((float) ($r->approved_amount ?? 0) - (float) $r->paid_amount, 2)
                    : 0.0,
                'creator_name' => $r->reviewer_name ?? '—',
                'date' => (string) ($r->reviewed_at ?? $r->request_date),
            ];
        });

        if (! empty($filters['receiving_safe_id'])) {
            $mapped = $mapped->filter(fn ($r) => in_array($filters['receiving_safe_id'], $r['receiving_safe_ids'], true));
        }

        return $mapped->values()->all();
    }

    // ── GET /hr/reports/advances ───────────────────────────────────────────────
    public function data(Request $request)
    {
        $filters = $this->reportFilters($request);

        return response()->json(['message' => 'ok', 'data' => $this->report($filters), 'period' => ['from' => $filters['from'], 'to' => $filters['to']]]);
    }

    // ── GET /hr/reports/advances/export?format=pdf|excel ──────────────────────
    public function export(Request $request)
    {
        $filters = $this->reportFilters($request);
        $rows = $this->report($filters);

        $columns = ['الموظف', 'الفرع', 'الخزنة الدافعة', 'الخزنة المستلمة', 'الحالة', 'المبلغ', 'المتبقي', 'المُنشئ (المعتمِد)', 'التاريخ'];
        $tableRows = array_map(fn ($r) => [
            $r['employee_name'], $r['branch_name'], $r['paying_safe_name'],
            $r['receiving_safe_names'] ? implode('، ', $r['receiving_safe_names']) : '—',
            $r['status_label'], $r['amount'], $r['remaining_balance'], $r['creator_name'], $r['date'],
        ], $rows);

        $filterLabels = array_filter([
            'من' => $filters['from'], 'إلى' => $filters['to'], 'الحالة' => $filters['status'] ? (self::STATUS_LABELS[$filters['status']] ?? $filters['status']) : null,
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير سلف الرواتب', $columns, $tableRows, $filterLabels, currencyColumns: [5, 6])
            : $this->exportService->pdf('تقرير سلف الرواتب', $columns, $tableRows, $filterLabels);
    }
}
