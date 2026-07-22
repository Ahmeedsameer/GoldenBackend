<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Services\Reports\ReportExportService;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.7 — Transfer Reports. Every dataset method here is called from
 * BOTH the on-screen `data()` endpoint and `export()` (PDF/Excel), so the
 * numbers a user sees on screen are exactly what gets exported — no
 * duplicated logic between the two paths.
 */
class TransferReportController extends Controller
{
    private const SUBMITTED_DELAY_HOURS = 48;
    private const SHIPPED_DELAY_HOURS = 72;

    public function __construct(private ReportExportService $exportService, private WarehouseResolver $warehouse) {}

    private function parseDates(Request $request): array
    {
        if ($request->filled('from') && $request->filled('to')) {
            return [$request->get('from'), $request->get('to')];
        }
        return [now()->subDays(30)->toDateString(), now()->toDateString()];
    }

    // ── GET /branch-operations/reports/transfers/summary ─────────────────────
    public function summary(Request $request)
    {
        [$from, $to] = $this->parseDates($request);
        $base = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59']);

        $total = (clone $base)->count();
        $open = (clone $base)->whereNotIn('status', [TransferRequest::STATUS_CLOSED, TransferRequest::STATUS_REJECTED])->count();
        $closed = (clone $base)->where('status', TransferRequest::STATUS_CLOSED)->count();
        $rejected = (clone $base)->where('status', TransferRequest::STATUS_REJECTED)->count();

        // Approval time: from the "submitted" ledger entry to approved_at (created_at is the draft time, not necessarily submission time).
        $avgApprovalMinutes = DB::table('transfer_request_logs as l')
            ->join('transfer_requests as tr', 'tr.id', '=', 'l.transfer_request_id')
            ->where('l.action', 'submitted')
            ->whereNotNull('tr.approved_at')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, l.created_at, tr.approved_at)) as avg_min')
            ->value('avg_min');

        $avgShippingMinutes = (clone $base)->whereNotNull('approved_at')->whereNotNull('shipped_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, approved_at, shipped_at)) as avg_min')->value('avg_min');

        $avgReceivingMinutes = (clone $base)->whereNotNull('shipped_at')->whereNotNull('received_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, shipped_at, received_at)) as avg_min')->value('avg_min');

        // Success rate = of transfers that reached a final decision, the share that completed successfully.
        $decided = $closed + $rejected;
        $successRate = $decided > 0 ? round($closed / $decided * 100, 1) : null;

        return response()->json([
            'message' => 'ok',
            'data' => [
                'period' => ['from' => $from, 'to' => $to],
                'total_transfers' => $total,
                'open_transfers' => $open,
                'closed_transfers' => $closed,
                'rejected_transfers' => $rejected,
                'avg_approval_minutes' => $avgApprovalMinutes !== null ? round((float) $avgApprovalMinutes, 1) : null,
                'avg_shipping_minutes' => $avgShippingMinutes !== null ? round((float) $avgShippingMinutes, 1) : null,
                'avg_receiving_minutes' => $avgReceivingMinutes !== null ? round((float) $avgReceivingMinutes, 1) : null,
                'success_rate_percent' => $successRate,
                'warehouse' => $this->warehouseSummary($from, $to),
            ],
        ]);
    }

    /** Phase 5.6 — Warehouse Response Time / Service Level, same computations as summary() above, scoped to source=warehouse. */
    private function warehouseSummary(string $from, string $to): ?array
    {
        $warehouseId = $this->warehouse->warehouseShopId();
        if (! $warehouseId) {
            return null;
        }

        $base = TransferRequest::where('source_shop_id', $warehouseId)->whereBetween('created_at', [$from, $to . ' 23:59:59']);

        $total = (clone $base)->count();
        $closed = (clone $base)->where('status', TransferRequest::STATUS_CLOSED)->count();
        $rejected = (clone $base)->where('status', TransferRequest::STATUS_REJECTED)->count();
        $decided = $closed + $rejected;

        $avgApprovalMinutes = DB::table('transfer_request_logs as l')
            ->join('transfer_requests as tr', 'tr.id', '=', 'l.transfer_request_id')
            ->where('tr.source_shop_id', $warehouseId)
            ->where('l.action', 'submitted')
            ->whereNotNull('tr.approved_at')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, l.created_at, tr.approved_at)) as avg_min')
            ->value('avg_min');

        $avgShippingMinutes = (clone $base)->whereNotNull('approved_at')->whereNotNull('shipped_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, approved_at, shipped_at)) as avg_min')->value('avg_min');

        return [
            'total_requests' => $total,
            'response_time_minutes' => $avgApprovalMinutes !== null ? round((float) $avgApprovalMinutes, 1) : null,
            'shipping_time_minutes' => $avgShippingMinutes !== null ? round((float) $avgShippingMinutes, 1) : null,
            'service_level_percent' => $decided > 0 ? round($closed / $decided * 100, 1) : null,
        ];
    }

    // ── Dataset builders — each returns [columns, rows-as-arrays, rows-for-display] ──

    private function byBranch(string $from, string $to): array
    {
        $shops = Shop::select('id', 'name')->get();
        $outgoing = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('source_shop_id as shop_id, COUNT(*) as cnt')->groupBy('source_shop_id')->pluck('cnt', 'shop_id');
        $incoming = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('destination_shop_id as shop_id, COUNT(*) as cnt')->groupBy('destination_shop_id')->pluck('cnt', 'shop_id');

        return $shops->map(fn ($s) => [
            'shop_id' => $s->id, 'shop_name' => $s->name,
            'outgoing' => (int) ($outgoing[$s->id] ?? 0), 'incoming' => (int) ($incoming[$s->id] ?? 0),
            'total' => (int) ($outgoing[$s->id] ?? 0) + (int) ($incoming[$s->id] ?? 0),
        ])->sortByDesc('total')->values()->all();
    }

    private function byProduct(string $from, string $to): array
    {
        return DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('p.id as product_id, p.name as product_name, p.sku, COUNT(DISTINCT tr.id) as transfer_count, SUM(tri.requested_quantity) as total_qty')
            ->groupBy('p.id', 'p.name', 'p.sku')->orderByDesc('total_qty')->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—', 'transfer_count' => (int) $r->transfer_count, 'total_qty' => round((float) $r->total_qty, 3)])
            ->all();
    }

    private function byCategory(string $from, string $to): array
    {
        return DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw("COALESCE(c.id, 0) as category_id, COALESCE(c.name, 'بدون فئة') as category_name, COUNT(DISTINCT tr.id) as transfer_count, SUM(tri.requested_quantity) as total_qty")
            ->groupBy('c.id', 'c.name')->orderByDesc('total_qty')->get()
            ->map(fn ($r) => ['category_id' => $r->category_id, 'category_name' => $r->category_name, 'transfer_count' => (int) $r->transfer_count, 'total_qty' => round((float) $r->total_qty, 3)])
            ->all();
    }

    private function byRequester(string $from, string $to, bool $managersOnly): array
    {
        $q = TransferRequest::join('users as u', 'u.id', '=', 'transfer_requests.requested_by')
            ->whereBetween('transfer_requests.created_at', [$from, $to . ' 23:59:59']);
        if ($managersOnly) {
            $q->where('u.role', 'manager');
        }

        return $q->selectRaw('u.id as user_id, u.name as user_name, u.role, COUNT(*) as transfer_count')
            ->groupBy('u.id', 'u.name', 'u.role')->orderByDesc('transfer_count')->get()
            ->map(fn ($r) => ['user_id' => $r->user_id, 'user_name' => $r->user_name, 'role' => $r->role, 'transfer_count' => (int) $r->transfer_count])
            ->all();
    }

    private function delayed(): array
    {
        $submitted = TransferRequest::where('status', TransferRequest::STATUS_SUBMITTED)
            ->where('created_at', '<', now()->subHours(self::SUBMITTED_DELAY_HOURS))
            ->with(['sourceShop:id,name', 'destinationShop:id,name'])->get();
        $shipped = TransferRequest::where('status', TransferRequest::STATUS_SHIPPED)
            ->where('shipped_at', '<', now()->subHours(self::SHIPPED_DELAY_HOURS))
            ->with(['sourceShop:id,name', 'destinationShop:id,name'])->get();

        return $submitted->merge($shipped)->map(fn ($t) => [
            'id' => $t->id, 'request_number' => $t->request_number, 'source' => $t->sourceShop->name ?? '', 'destination' => $t->destinationShop->name ?? '',
            'status' => $t->status, 'created_at' => $t->created_at->toDateTimeString(),
        ])->all();
    }

    private function pending(): array
    {
        return TransferRequest::whereIn('status', [
            TransferRequest::STATUS_SUBMITTED, TransferRequest::STATUS_APPROVED, TransferRequest::STATUS_PREPARING, TransferRequest::STATUS_SHIPPED,
        ])->with(['sourceShop:id,name', 'destinationShop:id,name'])->orderByDesc('created_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id, 'request_number' => $t->request_number, 'source' => $t->sourceShop->name ?? '', 'destination' => $t->destinationShop->name ?? '',
                'status' => $t->status, 'created_at' => $t->created_at->toDateTimeString(),
            ])->all();
    }

    private function internalInvoices(string $from, string $to): array
    {
        return DB::table('internal_transfer_invoices as i')
            ->join('transfer_requests as tr', 'tr.id', '=', 'i.transfer_request_id')
            ->join('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->join('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->whereBetween('i.date', [$from, $to])
            ->selectRaw('i.invoice_number, tr.request_number, src.name as source_name, dst.name as destination_name, i.date, i.reference_value, i.status')
            ->orderByDesc('i.date')->get()
            ->map(fn ($r) => [
                'invoice_number' => $r->invoice_number, 'request_number' => $r->request_number,
                'source_name' => $r->source_name, 'destination_name' => $r->destination_name,
                'date' => (string) $r->date, 'reference_value' => round((float) $r->reference_value, 2), 'status' => $r->status,
            ])->all();
    }

    private function itemIssues(string $from, string $to, string $column): array
    {
        return DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->join('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->join('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->where("tri.{$column}", '>', 0)
            ->selectRaw("tr.request_number, p.name as product_name, p.sku, src.name as source_name, dst.name as destination_name, tri.{$column} as qty, tr.received_at")
            ->orderByDesc('tr.received_at')->get()
            ->map(fn ($r) => [
                'request_number' => $r->request_number, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—',
                'source_name' => $r->source_name, 'destination_name' => $r->destination_name,
                'qty' => round((float) $r->qty, 3), 'received_at' => $r->received_at,
            ])->all();
    }

    /** Phase 5.6 — Transfer Type breakdown: Branch->Branch / Warehouse<->Branch / Emergency. Reuses is_emergency + WarehouseResolver, no new movement source. */
    private function byType(string $from, string $to): array
    {
        $warehouseId = $this->warehouse->warehouseShopId();
        $rows = TransferRequest::whereBetween('created_at', [$from, $to . ' 23:59:59'])->get(['id', 'source_shop_id', 'destination_shop_id', 'is_emergency']);

        $groups = $rows->groupBy(function ($t) use ($warehouseId) {
            if ($t->is_emergency) {
                return 'emergency';
            }
            return ($t->source_shop_id === $warehouseId || $t->destination_shop_id === $warehouseId) ? 'warehouse_branch' : 'branch_branch';
        });

        $labels = ['branch_branch' => 'فرع إلى فرع', 'warehouse_branch' => 'المستودع إلى فرع', 'emergency' => 'نقل طارئ'];

        return collect(['branch_branch', 'warehouse_branch', 'emergency'])
            ->map(fn ($key) => ['type' => $key, 'type_label' => $labels[$key], 'transfer_count' => $groups->get($key)?->count() ?? 0])
            ->all();
    }

    /** @return array<int, array<string, mixed>> Shared shape with pending()/delayed() — id/request_number/source/destination/status/created_at. */
    private function statusList(string $statusOrNull, ?bool $emergencyOnly = null): array
    {
        $q = TransferRequest::with(['sourceShop:id,name', 'destinationShop:id,name'])->orderByDesc('id');
        if ($statusOrNull) {
            $q->where('status', $statusOrNull);
        }
        if ($emergencyOnly !== null) {
            $q->where('is_emergency', $emergencyOnly);
        }

        return $q->get()->map(fn ($t) => [
            'id' => $t->id, 'request_number' => $t->request_number, 'source' => $t->sourceShop->name ?? '', 'destination' => $t->destinationShop->name ?? '',
            'status' => $t->status, 'created_at' => $t->created_at->toDateTimeString(),
        ])->all();
    }

    /** Items received but for less than what was actually shipped — a real partial receipt, not just a rounding artifact. */
    private function partialReceipts(string $from, string $to): array
    {
        return DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->join('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->join('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('tri.received_quantity')
            ->selectRaw('
                tr.request_number, p.name as product_name, p.sku, src.name as source_name, dst.name as destination_name,
                (SELECT COALESCE(SUM(quantity_shipped),0) FROM transfer_request_item_batches WHERE transfer_request_item_id = tri.id) as shipped_qty,
                tri.received_quantity, tr.received_at
            ')
            ->orderByDesc('tr.received_at')->get()
            ->filter(fn ($r) => (float) $r->shipped_qty > (float) $r->received_quantity)
            ->map(fn ($r) => [
                'request_number' => $r->request_number, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—',
                'source_name' => $r->source_name, 'destination_name' => $r->destination_name,
                'shipped_qty' => round((float) $r->shipped_qty, 3), 'received_qty' => round((float) $r->received_quantity, 3),
                'received_at' => $r->received_at,
            ])->values()->all();
    }

    /** Internal invoices whose transfer has shipped but not yet been received — a real "awaiting receipt" state, not a stored invoice status. */
    private function invoicesByReceiptState(string $from, string $to, bool $awaitingReceipt): array
    {
        $statuses = $awaitingReceipt ? [TransferRequest::STATUS_SHIPPED] : [TransferRequest::STATUS_RECEIVED, TransferRequest::STATUS_CLOSED];

        return DB::table('internal_transfer_invoices as i')
            ->join('transfer_requests as tr', 'tr.id', '=', 'i.transfer_request_id')
            ->join('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->join('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->whereBetween('i.date', [$from, $to])
            ->whereIn('tr.status', $statuses)
            ->selectRaw('i.invoice_number, tr.request_number, src.name as source_name, dst.name as destination_name, i.date, i.reference_value, tr.status')
            ->orderByDesc('i.date')->get()
            ->map(fn ($r) => [
                'invoice_number' => $r->invoice_number, 'request_number' => $r->request_number,
                'source_name' => $r->source_name, 'destination_name' => $r->destination_name,
                'date' => (string) $r->date, 'reference_value' => round((float) $r->reference_value, 2), 'status' => $r->status,
            ])->all();
    }

    /** Warehouse Outgoing / Shipment History — every transfer shipped FROM the warehouse, oldest-shipped-last. Same shape as pending()/delayed(). */
    private function warehouseOutgoing(string $from, string $to): array
    {
        $warehouseId = $this->warehouse->warehouseShopId();
        if (! $warehouseId) {
            return [];
        }

        return TransferRequest::where('source_shop_id', $warehouseId)
            ->whereNotNull('shipped_at')
            ->whereBetween('shipped_at', [$from, $to . ' 23:59:59'])
            ->with(['destinationShop:id,name'])->orderByDesc('shipped_at')->get()
            ->map(fn ($t) => [
                'id' => $t->id, 'request_number' => $t->request_number, 'source' => 'المستودع الرئيسي', 'destination' => $t->destinationShop->name ?? '',
                'status' => $t->status, 'created_at' => $t->shipped_at->toDateTimeString(),
            ])->all();
    }

    /** Warehouse Distribution — how much the warehouse has sent to each destination branch. */
    private function warehouseDistribution(string $from, string $to): array
    {
        $warehouseId = $this->warehouse->warehouseShopId();
        if (! $warehouseId) {
            return [];
        }

        return DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->where('tr.source_shop_id', $warehouseId)
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('dst.id as shop_id, dst.name as shop_name, COUNT(DISTINCT tr.id) as transfer_count, SUM(tri.requested_quantity) as total_qty')
            ->groupBy('dst.id', 'dst.name')->orderByDesc('total_qty')->get()
            ->map(fn ($r) => ['shop_id' => $r->shop_id, 'shop_name' => $r->shop_name, 'transfer_count' => (int) $r->transfer_count, 'total_qty' => round((float) $r->total_qty, 3)])
            ->all();
    }

    /** Most/Least Requested Products from the warehouse. */
    private function warehouseProductRanking(string $from, string $to, bool $mostRequested): array
    {
        $warehouseId = $this->warehouse->warehouseShopId();
        if (! $warehouseId) {
            return [];
        }

        return DB::table('transfer_request_items as tri')
            ->join('transfer_requests as tr', 'tr.id', '=', 'tri.transfer_request_id')
            ->join('products as p', 'p.id', '=', 'tri.product_id')
            ->where('tr.source_shop_id', $warehouseId)
            ->whereBetween('tr.created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('p.id as product_id, p.name as product_name, p.sku, COUNT(DISTINCT tr.id) as transfer_count, SUM(tri.requested_quantity) as total_qty')
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->when($mostRequested, fn ($q) => $q->orderByDesc('total_qty'), fn ($q) => $q->orderBy('total_qty'))
            ->limit(15)->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku ?? '—', 'transfer_count' => (int) $r->transfer_count, 'total_qty' => round((float) $r->total_qty, 3)])
            ->all();
    }

    // ── GET /branch-operations/reports/transfers/{type} ───────────────────────
    public function data(Request $request, string $type)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = $this->buildDataset($type, $from, $to);

        if ($rows === null) {
            return response()->json(['message' => 'نوع تقرير غير معروف', 'data' => []], 404);
        }

        return response()->json(['message' => 'ok', 'data' => $rows, 'period' => ['from' => $from, 'to' => $to]]);
    }

    private function buildDataset(string $type, string $from, string $to): ?array
    {
        return match ($type) {
            'by-branch' => $this->byBranch($from, $to),
            'by-product' => $this->byProduct($from, $to),
            'by-category' => $this->byCategory($from, $to),
            'by-manager' => $this->byRequester($from, $to, true),
            'by-employee' => $this->byRequester($from, $to, false),
            'by-type' => $this->byType($from, $to),
            'delayed' => $this->delayed(),
            'pending' => $this->pending(),
            'closed' => $this->statusList(TransferRequest::STATUS_CLOSED),
            'emergency' => $this->statusList('', true),
            'internal-invoices' => $this->internalInvoices($from, $to),
            'damaged' => $this->itemIssues($from, $to, 'damaged_quantity'),
            'missing' => $this->itemIssues($from, $to, 'missing_quantity'),
            'partial-receipts' => $this->partialReceipts($from, $to),
            'invoice-awaiting-receipt' => $this->invoicesByReceiptState($from, $to, true),
            'invoice-completed' => $this->invoicesByReceiptState($from, $to, false),
            'warehouse-outgoing' => $this->warehouseOutgoing($from, $to),
            'warehouse-distribution' => $this->warehouseDistribution($from, $to),
            'most-requested-products' => $this->warehouseProductRanking($from, $to, true),
            'least-requested-products' => $this->warehouseProductRanking($from, $to, false),
            default => null,
        };
    }

    private const COLUMNS = [
        'by-branch' => ['الفرع', 'وارد', 'صادر', 'الإجمالي'],
        'by-product' => ['المنتج', 'الكود', 'عدد التحويلات', 'الكمية الإجمالية'],
        'by-category' => ['الفئة', 'عدد التحويلات', 'الكمية الإجمالية'],
        'by-manager' => ['المستخدم', 'الدور', 'عدد الطلبات'],
        'by-employee' => ['المستخدم', 'الدور', 'عدد الطلبات'],
        'by-type' => ['نوع التحويل', 'عدد الطلبات'],
        'delayed' => ['رقم الطلب', 'من', 'إلى', 'الحالة', 'تاريخ الإنشاء'],
        'pending' => ['رقم الطلب', 'من', 'إلى', 'الحالة', 'تاريخ الإنشاء'],
        'closed' => ['رقم الطلب', 'من', 'إلى', 'الحالة', 'تاريخ الإنشاء'],
        'emergency' => ['رقم الطلب', 'من', 'إلى', 'الحالة', 'تاريخ الإنشاء'],
        'internal-invoices' => ['رقم الفاتورة', 'رقم الطلب', 'من', 'إلى', 'التاريخ', 'القيمة المرجعية', 'الحالة'],
        'damaged' => ['رقم الطلب', 'المنتج', 'الكود', 'من', 'إلى', 'الكمية التالفة', 'تاريخ الاستلام'],
        'missing' => ['رقم الطلب', 'المنتج', 'الكود', 'من', 'إلى', 'الكمية المفقودة', 'تاريخ الاستلام'],
        'partial-receipts' => ['رقم الطلب', 'المنتج', 'الكود', 'من', 'إلى', 'الكمية المشحونة', 'الكمية المستلمة', 'تاريخ الاستلام'],
        'invoice-awaiting-receipt' => ['رقم الفاتورة', 'رقم الطلب', 'من', 'إلى', 'التاريخ', 'القيمة المرجعية', 'الحالة'],
        'invoice-completed' => ['رقم الفاتورة', 'رقم الطلب', 'من', 'إلى', 'التاريخ', 'القيمة المرجعية', 'الحالة'],
        'warehouse-outgoing' => ['رقم الطلب', 'من', 'إلى', 'الحالة', 'تاريخ الشحن'],
        'warehouse-distribution' => ['الفرع', 'عدد التحويلات', 'الكمية الإجمالية'],
        'most-requested-products' => ['المنتج', 'الكود', 'عدد التحويلات', 'الكمية الإجمالية'],
        'least-requested-products' => ['المنتج', 'الكود', 'عدد التحويلات', 'الكمية الإجمالية'],
    ];

    private const TITLES = [
        'by-branch' => 'التحويلات حسب الفرع', 'by-product' => 'التحويلات حسب المنتج', 'by-category' => 'التحويلات حسب الفئة',
        'by-manager' => 'التحويلات حسب المدير', 'by-employee' => 'التحويلات حسب الموظف', 'by-type' => 'التحويلات حسب النوع',
        'delayed' => 'التحويلات المتأخرة', 'pending' => 'التحويلات المعلّقة', 'closed' => 'التحويلات المغلقة',
        'emergency' => 'التحويلات الطارئة', 'internal-invoices' => 'تقرير فواتير النقل الداخلية',
        'damaged' => 'التالف أثناء النقل', 'missing' => 'المفقود أثناء النقل', 'partial-receipts' => 'الاستلام الجزئي',
        'invoice-awaiting-receipt' => 'فواتير بانتظار الاستلام', 'invoice-completed' => 'فواتير مكتملة',
        'warehouse-outgoing' => 'صادر من المستودع الرئيسي', 'warehouse-distribution' => 'توزيع المستودع على الفروع',
        'most-requested-products' => 'الأكثر طلباً من المستودع', 'least-requested-products' => 'الأقل طلباً من المستودع',
    ];

    private function toRow(string $type, array $r): array
    {
        return match ($type) {
            'by-branch' => [$r['shop_name'], $r['incoming'], $r['outgoing'], $r['total']],
            'by-product', 'most-requested-products', 'least-requested-products' => [$r['product_name'], $r['sku'], $r['transfer_count'], $r['total_qty']],
            'by-category' => [$r['category_name'], $r['transfer_count'], $r['total_qty']],
            'by-manager', 'by-employee' => [$r['user_name'], $r['role'], $r['transfer_count']],
            'by-type' => [$r['type_label'], $r['transfer_count']],
            'delayed', 'pending', 'closed', 'emergency' => [$r['request_number'], $r['source'], $r['destination'], $r['status'], $r['created_at']],
            'internal-invoices', 'invoice-awaiting-receipt', 'invoice-completed' => [$r['invoice_number'], $r['request_number'], $r['source_name'], $r['destination_name'], $r['date'], $r['reference_value'], $r['status']],
            'damaged', 'missing' => [$r['request_number'], $r['product_name'], $r['sku'], $r['source_name'], $r['destination_name'], $r['qty'], $r['received_at']],
            'partial-receipts' => [$r['request_number'], $r['product_name'], $r['sku'], $r['source_name'], $r['destination_name'], $r['shipped_qty'], $r['received_qty'], $r['received_at']],
            'warehouse-outgoing' => [$r['request_number'], $r['source'], $r['destination'], $r['status'], $r['created_at']],
            'warehouse-distribution' => [$r['shop_name'], $r['transfer_count'], $r['total_qty']],
            default => [],
        };
    }

    // ── GET /branch-operations/reports/transfers/{type}/export?format=pdf|excel ──
    public function export(Request $request, string $type)
    {
        [$from, $to] = $this->parseDates($request);

        $rows = $this->buildDataset($type, $from, $to);

        if ($rows === null || ! isset(self::COLUMNS[$type])) {
            abort(404, 'نوع تقرير غير معروف');
        }

        $columns = self::COLUMNS[$type];
        $tableRows = array_map(fn ($r) => $this->toRow($type, $r), $rows);
        $title = self::TITLES[$type];
        $filters = array_filter(['من' => $from, 'إلى' => $to]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel($title, $columns, $tableRows, $filters)
            : $this->exportService->pdf($title, $columns, $tableRows, $filters);
    }

    // ── Phase 5.8 — Internal Transfer Invoice dedicated report ────────────────
    // Same dataset method feeds both data() and export() below, and reuses
    // ReportExportService exactly like every other report in this file — no
    // separate reporting engine, just a richer filter set than the generic
    // 'internal-invoices' tab (source/destination/type/status/creator/
    // approver/receiver/search), because those don't fit the tab's plain
    // date-range shape.

    private function invoiceReportFilters(Request $request): array
    {
        [$from, $to] = $this->parseDates($request);

        return [
            'from' => $from, 'to' => $to,
            'source_shop_id' => $request->filled('source_shop_id') ? (int) $request->get('source_shop_id') : null,
            'destination_shop_id' => $request->filled('destination_shop_id') ? (int) $request->get('destination_shop_id') : null,
            'transfer_type' => $request->get('transfer_type'),
            'status' => $request->get('status'),
            'creator_id' => $request->filled('creator_id') ? (int) $request->get('creator_id') : null,
            'approver_id' => $request->filled('approver_id') ? (int) $request->get('approver_id') : null,
            'receiver_id' => $request->filled('receiver_id') ? (int) $request->get('receiver_id') : null,
            'search' => $request->get('search'),
        ];
    }

    private function invoiceReport(array $filters): array
    {
        $query = DB::table('internal_transfer_invoices as i')
            ->join('transfer_requests as tr', 'tr.id', '=', 'i.transfer_request_id')
            ->join('shops as src', 'src.id', '=', 'tr.source_shop_id')
            ->join('shops as dst', 'dst.id', '=', 'tr.destination_shop_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'tr.requested_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'tr.approved_by')
            ->whereBetween('i.date', [$filters['from'], $filters['to']])
            ->selectRaw('
                i.invoice_number, i.date, i.created_at as generated_at, i.reference_value, i.status as invoice_status,
                tr.id as transfer_id, tr.request_number, tr.status as transfer_status, tr.is_emergency,
                src.id as source_id, src.name as source_name, dst.id as destination_id, dst.name as destination_name,
                creator.id as creator_id, creator.name as creator_name, approver.id as approver_id, approver.name as approver_name,
                (SELECT u.id FROM transfer_request_logs l JOIN users u ON u.id = l.user_id WHERE l.transfer_request_id = tr.id AND l.action = "received" ORDER BY l.id DESC LIMIT 1) as receiver_id,
                (SELECT u.name FROM transfer_request_logs l JOIN users u ON u.id = l.user_id WHERE l.transfer_request_id = tr.id AND l.action = "received" ORDER BY l.id DESC LIMIT 1) as receiver_name
            ');

        if (! empty($filters['source_shop_id'])) {
            $query->where('tr.source_shop_id', $filters['source_shop_id']);
        }
        if (! empty($filters['destination_shop_id'])) {
            $query->where('tr.destination_shop_id', $filters['destination_shop_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('tr.status', $filters['status']);
        }
        if (! empty($filters['creator_id'])) {
            $query->where('tr.requested_by', $filters['creator_id']);
        }
        if (! empty($filters['approver_id'])) {
            $query->where('tr.approved_by', $filters['approver_id']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(fn ($q) => $q->where('i.invoice_number', 'like', "%{$term}%")->orWhere('tr.request_number', 'like', "%{$term}%"));
        }

        $rows = $query->orderByDesc('i.date')->get()->map(function ($r) {
            $transferType = $r->is_emergency
                ? 'emergency'
                : (($this->warehouse->isWarehouse($r->source_id) || $this->warehouse->isWarehouse($r->destination_id)) ? 'warehouse_branch' : 'branch_branch');

            return [
                'invoice_number' => $r->invoice_number, 'request_number' => $r->request_number,
                'date' => (string) $r->date, 'generated_at' => (string) $r->generated_at,
                'source_name' => $r->source_name, 'destination_name' => $r->destination_name,
                'transfer_type' => $transferType,
                'transfer_type_label' => ['branch_branch' => 'فرع إلى فرع', 'warehouse_branch' => 'المستودع إلى فرع', 'emergency' => 'نقل طارئ'][$transferType],
                'status' => $r->transfer_status,
                'creator_name' => $r->creator_name ?? '—', 'approver_name' => $r->approver_name ?? '—', 'receiver_name' => $r->receiver_name ?? '—',
                'receiver_id' => $r->receiver_id, 'reference_value' => round((float) $r->reference_value, 2),
            ];
        });

        // receiver_id and transfer_type filters can't be applied in SQL (receiver is a
        // derived subquery, type is derived in PHP from is_emergency + WarehouseResolver)
        // — filtered here instead of duplicating that derivation logic in raw SQL.
        if (! empty($filters['receiver_id'])) {
            $rows = $rows->where('receiver_id', $filters['receiver_id']);
        }
        if (! empty($filters['transfer_type'])) {
            $rows = $rows->where('transfer_type', $filters['transfer_type']);
        }

        return $rows->values()->all();
    }

    // ── GET /branch-operations/reports/transfer-invoices ──────────────────────
    public function invoiceReportData(Request $request)
    {
        $filters = $this->invoiceReportFilters($request);

        return response()->json(['message' => 'ok', 'data' => $this->invoiceReport($filters), 'period' => ['from' => $filters['from'], 'to' => $filters['to']]]);
    }

    // ── GET /branch-operations/reports/transfer-invoices/export?format=pdf|excel ──
    public function invoiceReportExport(Request $request)
    {
        $filters = $this->invoiceReportFilters($request);
        $rows = $this->invoiceReport($filters);

        $columns = ['رقم الفاتورة', 'رقم الطلب', 'التاريخ', 'المصدر', 'الوجهة', 'نوع النقل', 'الحالة', 'أنشأ بواسطة', 'اعتمد بواسطة', 'استلم بواسطة', 'القيمة المرجعية'];
        $tableRows = array_map(fn ($r) => [
            $r['invoice_number'], $r['request_number'], $r['date'], $r['source_name'], $r['destination_name'],
            $r['transfer_type_label'], $r['status'], $r['creator_name'], $r['approver_name'], $r['receiver_name'], $r['reference_value'],
        ], $rows);

        $filterLabels = array_filter([
            'من' => $filters['from'], 'إلى' => $filters['to'], 'الحالة' => $filters['status'],
        ], fn ($v) => $v !== null);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير فواتير النقل الداخلية', $columns, $tableRows, $filterLabels)
            : $this->exportService->pdf('تقرير فواتير النقل الداخلية', $columns, $tableRows, $filterLabels);
    }
}
