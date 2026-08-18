<?php

namespace App\Modules\Pricing\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SupplyItem;
use App\Modules\Pricing\Services\PricingService;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\Request;

class PricingController extends Controller
{
    public function __construct(
        private PricingService $pricingService,
        private ReportExportService $exportService,
    ) {}

    /** GET /api/pricing — admin + manager (view only). */
    public function index(Request $request)
    {
        return response()->json([
            'message' => 'ok',
            'data' => $this->pricingService->listPricing($request->input('search')),
        ]);
    }

    /** GET /api/pricing/export?format=pdf|excel — admin + manager, same data as the list. */
    public function export(Request $request)
    {
        $rows = $this->pricingService->listPricing($request->input('search'));
        $statusLabel = fn (string $s) => ['needs_review' => 'يحتاج مراجعة', 'no_price' => 'بلا سعر', 'ok' => 'محدَّث'][$s] ?? $s;
        $typeLabel = fn (string $t) => match ($t) {
            'COMPOUND' => 'عطر مركّب',
            'RAW_MATERIAL' => 'مادة خام',
            'PACKAGING' => 'عبوة / تغليف',
            default => 'منتج جاهز',
        };

        $columns = ['المنتج', 'النوع', 'التكلفة الحالية', 'سعر البيع', 'الربح المتوقع', 'نسبة الربح %', 'آخر تحديث', 'الحالة'];
        $tableRows = array_map(fn ($r) => [
            $r['name'], $typeLabel($r['product_type']),
            $r['current_cost'] ?? '—', $r['selling_price'] ?? '—',
            $r['estimated_profit'] ?? '—', $r['profit_percent'] ?? '—',
            $r['last_price_update'] ? substr($r['last_price_update'], 0, 10) : '—',
            $statusLabel($r['status']),
        ], $rows);

        $filters = array_filter(['بحث' => $request->input('search')]);

        return $request->input('format') === 'excel'
            ? $this->exportService->excel('تقرير إدارة الأسعار', $columns, $tableRows, $filters, [2, 3, 4])
            : $this->exportService->pdf('تقرير إدارة الأسعار', $columns, $tableRows, $filters);
    }

    /** GET /api/pricing/{id} — product pricing detail. */
    public function show(int $id)
    {
        $product = Product::findOrFail($id);

        return response()->json([
            'message' => 'ok',
            'data' => $this->pricingService->detailFor($product),
        ]);
    }

    /** GET /api/pricing/{id}/history */
    public function history(int $id)
    {
        $product = Product::findOrFail($id);
        $history = $this->pricingService->history($product, (int) request('per_page', 20));

        return response()->json([
            'message' => 'ok',
            'data' => $history->items(),
            'meta' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'total' => $history->total(),
            ],
        ]);
    }

    /** GET /api/pricing/update-preview — admin only. */
    public function updatePreview()
    {
        return response()->json([
            'message' => 'ok',
            'data' => $this->pricingService->previewPriceUpdate(),
        ]);
    }

    /** POST /api/pricing/update-prices — admin only. Cost-only, never touches selling_price. */
    public function applyUpdate(Request $request)
    {
        $data = $request->validate(['product_ids' => 'nullable|array', 'product_ids.*' => 'integer']);
        $changes = $this->pricingService->applyPriceUpdate(auth()->user(), $data['product_ids'] ?? null);

        return response()->json([
            'message' => count($changes) . ' منتج تم تحديث تكلفته',
            'data' => $changes,
        ]);
    }

    /** GET /api/pricing/{id}/batches — admin + manager. Every batch for this
     *  product plus current_fifo_price/next_batch_price/remaining_in_current_batch. */
    public function batches(int $id)
    {
        $product = Product::findOrFail($id);

        return response()->json([
            'message' => 'ok',
            'data' => array_merge(
                $this->pricingService->listBatches($product),
                $this->pricingService->batchStatusFor($product),
            ),
        ]);
    }

    /** PUT /api/pricing/{id}/batches/{supplyItemId}/price — admin only.
     *  Prices exactly ONE unpriced batch — immutable, 422 if already priced. */
    public function priceBatch(int $id, int $supplyItemId, Request $request)
    {
        $data = $request->validate([
            'selling_price' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($id);
        $batch = SupplyItem::findOrFail($supplyItemId);

        $this->pricingService->priceBatch($product, $batch, (float) $data['selling_price'], $data['reason'] ?? null, auth()->user());

        return response()->json([
            'message' => 'تم تسعير الدفعة بنجاح',
            'data' => $this->pricingService->detailFor($product->fresh()),
        ]);
    }

    /** PATCH /api/pricing/{id}/batches/{supplyItemId}/price — admin only.
     *  Edits an ALREADY-priced batch's selling price (only affects future
     *  sales — every past invoice keeps its own frozen price/cost/profit
     *  snapshot). Distinct from PUT .../price (priceBatch, first-time-only). */
    public function updateBatchPrice(int $id, int $supplyItemId, Request $request)
    {
        $data = $request->validate([
            'selling_price' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($id);
        $batch = SupplyItem::findOrFail($supplyItemId);

        $this->pricingService->updateBatchPrice($product, $batch, (float) $data['selling_price'], $data['reason'] ?? null, auth()->user());

        return response()->json([
            'message' => 'تم تعديل سعر الدفعة بنجاح',
            'data' => $this->pricingService->detailFor($product->fresh()),
        ]);
    }

    /** POST /api/pricing/{id}/batches/{supplyItemId}/archive — admin only.
     *  Retires a batch from future sale — never a physical delete; see
     *  PricingService::archiveBatch() / Batch Deletion Protection. */
    public function archiveBatch(int $id, int $supplyItemId)
    {
        $product = Product::findOrFail($id);
        $batch = SupplyItem::findOrFail($supplyItemId);

        $this->pricingService->archiveBatch($product, $batch);

        return response()->json([
            'message' => 'تمت أرشفة الدفعة بنجاح',
            'data' => $this->pricingService->detailFor($product->fresh()),
        ]);
    }

    /** POST /api/pricing/batches/bulk-price — admin only. Prices several
     *  unpriced batches (any mix of products) in one request; each batch
     *  keeps its own independently-submitted price — see
     *  PricingService::bulkPriceBatches() for why this never applies one
     *  shared price to everything. */
    public function bulkPriceBatches(Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.supply_item_id' => 'required|integer',
            'items.*.selling_price' => 'required|numeric|min:0.01',
            'items.*.reason' => 'nullable|string|max:255',
        ]);

        $results = $this->pricingService->bulkPriceBatches($data['items'], auth()->user());

        return response()->json([
            'message' => count(array_filter($results, fn ($r) => $r['success'])) . ' من ' . count($results) . ' دفعة تم تسعيرها بنجاح',
            'data' => $results,
        ]);
    }

    /** PUT /api/pricing/{id}/selling-price — admin only. */
    public function updateSellingPrice(int $id, Request $request)
    {
        $data = $request->validate([
            'selling_price' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:255',
        ]);

        $product = Product::findOrFail($id);
        $this->pricingService->updateSellingPrice($product, (float) $data['selling_price'], $data['reason'] ?? null, auth()->user());

        return response()->json([
            'message' => 'تم تحديث سعر البيع بنجاح',
            'data' => $this->pricingService->detailFor($product->fresh()),
        ]);
    }
}
