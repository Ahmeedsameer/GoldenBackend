<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Safe;
use App\Models\SafeTransaction;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminFinancialReportController extends Controller
{
    public function __construct(private ReportExportService $exportService) {}

    // Real economic flows — internal transfers are excluded from all P&L calcs.
    // Payment Methods Phase 2: 'bank_charge'/'bank_charge_reversal' added so the
    // card processing fee actually shows up as a real expense here, per spec
    // ("must participate in accounting, not just be stored"). Other pre-existing
    // gaps (advance_disbursement, supplier_payment, ... never added here) are
    // left untouched — out of scope for this change.
    // Payroll+Treasury integration: 'salary_payment' added so paid salaries
    // actually show up here too — same reasoning as bank_charge above (a real,
    // reportable expense, not one to silently omit).
    private const REAL_TYPES = [
        'sale', 'refund',
        'admin_deposit', 'admin_withdrawal',
        'manager_deposit', 'manager_expense',
        'bank_charge', 'bank_charge_reversal',
        'salary_payment',
    ];

    /** GET /api/admin/reports/financial/export?format=pdf|excel — the safe-transaction ledger for the period. */
    public function export(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = SafeTransaction::with(['safe.shop:id,name', 'currency:id,name,symbol', 'reason:id,name', 'user:id,name'])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        if ($request->filled('shop_id')) {
            $shopId = (int) $request->get('shop_id');
            $q->whereHas('safe', fn ($sq) => $sq->where('shop_id', $shopId));
        }
        if ($request->filled('type')) { $q->where('type', $request->get('type')); }

        $transactions = $q->orderByDesc('created_at')->get();

        $columns = ['التاريخ', 'الفرع', 'النوع', 'الاتجاه', 'العملة', 'المبلغ', 'السبب', 'المستخدم'];
        $rows = $transactions->map(fn ($tx) => [
            $tx->created_at?->toDateTimeString(), $tx->safe?->shop?->name ?? '—', $tx->type, $tx->direction,
            $tx->currency?->name ?? '—', round((float) $tx->amount, 2), $tx->reason?->name ?? '—', $tx->user?->name ?? '—',
        ])->all();

        $filters = array_filter(['من' => $from, 'إلى' => $to]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('التقرير المالي', $columns, $rows, $filters, [5])
            : $this->exportService->pdf('التقرير المالي', $columns, $rows, $filters);
    }

    // ── Date range resolver ──────────────────────────────────────────
    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        $period = $request->get('period', 'month');
        $from = match ($period) {
            'today' => now()->startOfDay()->toDateString(),
            'week'  => now()->startOfWeek()->toDateString(),
            'month' => now()->startOfMonth()->toDateString(),
            'year'  => now()->startOfYear()->toDateString(),
            default => now()->startOfMonth()->toDateString(),
        };
        return [$from, now()->toDateString()];
    }

    private function baseTxQuery(Request $request, string $from, string $to)
    {
        $q = SafeTransaction::whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('type', self::REAL_TYPES);

        if ($request->filled('shop_id')) {
            $shopId = (int) $request->get('shop_id');
            $q->whereHas('safe', fn ($sq) => $sq->where('shop_id', $shopId));
        }
        return $q;
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/financial/summary
    // ════════════════════════════════════════════════════════════════
    public function summary(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $q = $this->baseTxQuery($request, $from, $to);

        $agg = (clone $q)->selectRaw("
            COUNT(*) as tx_count,
            COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount ELSE 0 END), 0) as total_in,
            COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as total_out,
            COALESCE(SUM(CASE WHEN type = 'sale'                              THEN amount ELSE 0 END), 0) as sales_revenue,
            COALESCE(SUM(CASE WHEN type = 'refund'                            THEN amount ELSE 0 END), 0) as refunds,
            COALESCE(SUM(CASE WHEN type IN ('admin_deposit','manager_deposit') THEN amount ELSE 0 END), 0) as deposits,
            COALESCE(SUM(CASE WHEN type = 'admin_withdrawal'                  THEN amount ELSE 0 END), 0) as withdrawals,
            COALESCE(SUM(CASE WHEN type = 'manager_expense'                   THEN amount ELSE 0 END), 0) as expenses,
            COALESCE(SUM(CASE WHEN type = 'bank_charge'                      THEN amount ELSE 0 END), 0) as bank_charges,
            COALESCE(SUM(CASE WHEN type = 'bank_charge_reversal'              THEN amount ELSE 0 END), 0) as bank_charge_reversals
        ")->first();

        $totalIn  = (float) ($agg->total_in  ?? 0);
        $totalOut = (float) ($agg->total_out ?? 0);

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period'        => ['from' => $from, 'to' => $to],
                'tx_count'      => (int)   ($agg->tx_count     ?? 0),
                'total_in'      => round($totalIn,  2),
                'total_out'     => round($totalOut, 2),
                'net_flow'      => round($totalIn - $totalOut, 2),
                'sales_revenue' => round((float) ($agg->sales_revenue ?? 0), 2),
                'refunds'       => round((float) ($agg->refunds       ?? 0), 2),
                'deposits'      => round((float) ($agg->deposits      ?? 0), 2),
                'withdrawals'   => round((float) ($agg->withdrawals   ?? 0), 2),
                'expenses'      => round((float) ($agg->expenses      ?? 0), 2),
                'bank_charges'  => round((float) (($agg->bank_charges ?? 0) - ($agg->bank_charge_reversals ?? 0)), 2),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/financial/trend
    // Daily in/out totals for the selected period
    // ════════════════════════════════════════════════════════════════
    public function trend(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $q = $this->baseTxQuery($request, $from, $to);

        $rows = (clone $q)->selectRaw("
                DATE(created_at) as date,
                COALESCE(SUM(CASE WHEN direction = 'in'  THEN amount ELSE 0 END), 0) as total_in,
                COALESCE(SUM(CASE WHEN direction = 'out' THEN amount ELSE 0 END), 0) as total_out
            ")
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get()
            ->map(fn ($r) => [
                'date'      => (string) $r->date,
                'total_in'  => round((float) $r->total_in,  2),
                'total_out' => round((float) $r->total_out, 2),
            ]);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/financial/by-shop
    // Per-safe financial breakdown (all shops or one shop)
    // ════════════════════════════════════════════════════════════════
    public function byShop(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        // Aggregate all transactions in one query, group by safe_id + type + direction
        $txData = SafeTransaction::whereBetween(DB::raw('DATE(created_at)'), [$from, $to])
            ->whereIn('type', self::REAL_TYPES)
            ->selectRaw('safe_id, type, direction, COALESCE(SUM(amount), 0) as total')
            ->groupBy('safe_id', 'type', 'direction')
            ->get()
            ->groupBy('safe_id');

        $safes = Safe::with(['shop:id,name', 'safeType:id,name'])
            ->whereNotNull('shop_id')
            ->when($request->filled('shop_id'), fn ($q) => $q->where('shop_id', (int) $request->get('shop_id')))
            ->get();

        $rows = $safes->map(function ($safe) use ($txData) {
            $txRows = $txData->get($safe->id, collect());

            $totalIn  = $txRows->where('direction', 'in')->sum('total');
            $totalOut = $txRows->where('direction', 'out')->sum('total');
            $sales    = $txRows->where('type', 'sale')->sum('total');
            $deposits = $txRows->whereIn('type', ['admin_deposit', 'manager_deposit'])->sum('total');
            $expenses = $txRows->whereIn('type', ['admin_withdrawal', 'manager_expense', 'bank_charge'])->sum('total');
            $refunds  = $txRows->where('type', 'refund')->sum('total');

            return [
                'safe_id'   => $safe->id,
                'shop_id'   => $safe->shop_id,
                'shop_name' => $safe->shop?->name    ?? '—',
                'type_name' => $safe->safeType?->name ?? '—',
                'total_in'  => round((float) $totalIn,  2),
                'total_out' => round((float) $totalOut, 2),
                'net_flow'  => round((float) ($totalIn - $totalOut), 2),
                'sales'     => round((float) $sales,    2),
                'deposits'  => round((float) $deposits, 2),
                'expenses'  => round((float) $expenses, 2),
                'refunds'   => round((float) $refunds,  2),
            ];
        })
        ->sortByDesc('total_in')
        ->values();

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/financial/balances
    // Current safe balances (not time-filtered — always live)
    // ════════════════════════════════════════════════════════════════
    public function balances(Request $request)
    {
        $q = Safe::with(['shop:id,name', 'safeType:id,name,kind', 'balances.currency'])
            ->whereNotNull('shop_id');

        if ($request->filled('shop_id')) {
            $q->where('shop_id', (int) $request->get('shop_id'));
        }

        $rows = $q->orderBy('shop_id')->get()->map(fn ($safe) => [
            'safe_id'   => $safe->id,
            'shop_id'   => $safe->shop_id,
            'shop_name' => $safe->shop?->name    ?? '—',
            'type_name' => $safe->safeType?->name ?? '—',
            'is_active' => (bool) $safe->is_active,
            'balances'  => $safe->balances->map(fn ($b) => [
                'currency'      => $b->currency?->name   ?? '—',
                'currency_code' => $b->currency?->code   ?? '',
                'symbol'        => $b->currency?->symbol ?? '',
                'balance'       => round((float) $b->balance, 2),
            ])->values(),
        ]);

        return response()->json(['message' => 'ok', 'data' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    // GET /api/admin/reports/financial/transactions
    // Full paginated transaction log with filters
    // ════════════════════════════════════════════════════════════════
    public function transactions(Request $request)
    {
        [$from, $to] = $this->parseDates($request);

        $q = SafeTransaction::with([
                'safe.shop:id,name',
                'currency:id,name,code,symbol',
                'reason:id,name',
                'user:id,name',
            ])
            ->whereBetween(DB::raw('DATE(created_at)'), [$from, $to]);

        if ($request->filled('shop_id')) {
            $shopId = (int) $request->get('shop_id');
            $q->whereHas('safe', fn ($sq) => $sq->where('shop_id', $shopId));
        }
        if ($request->filled('type'))       { $q->where('type',      $request->get('type')); }
        if ($request->filled('direction'))  { $q->where('direction', $request->get('direction')); }
        if ($request->filled('min_amount')) { $q->where('amount', '>=', (float) $request->get('min_amount')); }
        if ($request->filled('max_amount')) { $q->where('amount', '<=', (float) $request->get('max_amount')); }

        $perPage = min((int) $request->get('per_page', 30), 100);
        $result  = $q->orderByDesc('created_at')->paginate($perPage);

        $result->getCollection()->transform(fn ($tx) => [
            'id'         => $tx->id,
            'safe_id'    => $tx->safe_id,
            'shop_name'  => $tx->safe?->shop?->name ?? '—',
            'type'       => $tx->type,
            'direction'  => $tx->direction,
            'currency'   => $tx->currency?->name   ?? '—',
            'symbol'     => $tx->currency?->symbol ?? '',
            'amount'     => round((float) $tx->amount, 2),
            'reason'     => $tx->reason?->name,
            'note'       => $tx->note,
            'user_name'  => $tx->user?->name ?? '—',
            'created_at' => $tx->created_at?->toDateTimeString(),
        ]);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }
}
