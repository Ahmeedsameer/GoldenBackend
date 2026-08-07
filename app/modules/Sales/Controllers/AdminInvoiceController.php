<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Sales\Requests\EditInvoiceRequest;
use App\Modules\Sales\Requests\UpdateInvoiceStatusRequest;
use App\Modules\Sales\Services\SalesService;

/**
 * Admin review of invoices across all shops — primarily the pending queue
 * (invoices flagged because an item sold below its category minimum). The admin
 * can approve (accept) or reject (cancel) each one.
 */
class AdminInvoiceController extends Controller
{
    public function __construct(private SalesService $salesService) {}

    /**
     * GET /api/admin/invoices?status=pending&shop_id=&date_from=&date_to=
     * Defaults to the pending queue.
     */
    public function index()
    {
        $filters = [
            'status'    => request('status', 'pending'),
            'shop_id'   => request('shop_id'),
            'date_from' => request('date_from'),
            'date_to'   => request('date_to'),
        ];
        $perPage = request()->integer('per_page', 15);

        $invoices = $this->salesService->getInvoicesForAdmin($filters, $perPage);

        return response()->json([
            'message' => 'ok',
            'data'    => $invoices,
        ]);
    }

    /**
     * GET /api/admin/invoices/{id}
     */
    public function show(string $id)
    {
        $invoice = Invoice::with([
            'customer',
            'seller:id,name',
            'shop:id,name',
            'items.product:id,name,sku,scalar,category_id,purchase_cost',
            'items.product.category:id,name,minimum_sell_price',
            'items.goods.supplyItem:id,unit_price',
            'payments.currency:id,code,symbol',
        ])->findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $invoice]);
    }

    /**
     * PUT /api/admin/invoices/{id}/status  — approve (approved) or reject (cancelled).
     * Not seller-scoped: the admin reviews every shop's invoices.
     */
    public function updateStatus(UpdateInvoiceStatusRequest $request, string $id)
    {
        $invoice = Invoice::findOrFail($id);
        $updated = $this->salesService->updateStatus($invoice, $request->validated('status'));

        $label = $updated->status === 'approved' ? 'اعتماد' : 'رفض';

        return response()->json([
            'message' => "تم {$label} الفاتورة بنجاح",
            'data'    => $updated,
        ]);
    }

    /**
     * POST /api/admin/invoices/{id}/cancel — reverses stock AND money (see
     * SalesService::cancel), unlike updateStatus('cancelled') above which is
     * only the pending-review reject path. Not shop-scoped: admin manages
     * every shop's invoices.
     */
    public function cancel(\Illuminate\Http\Request $request, string $id)
    {
        $data = $request->validate(['reason' => 'nullable|string']);
        $invoice = Invoice::findOrFail($id);
        $cancelled = $this->salesService->cancel($invoice, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'message' => 'تم إلغاء الفاتورة بنجاح، وتمت إعادة المخزون واسترجاع المبلغ',
            'data'    => $cancelled,
        ]);
    }

    /**
     * PUT /api/admin/invoices/{id}/edit — restores stock to the original
     * batches then rebuilds via the same FIFO engine a new sale uses; the
     * financial difference (if any) posts as a single safe adjustment
     * transaction. Not shop-scoped: admin can edit any shop's invoice.
     */
    public function edit(EditInvoiceRequest $request, string $id)
    {
        $invoice = Invoice::findOrFail($id);
        $result = $this->salesService->editInvoice(
            $invoice,
            $request->validated(),
            $request->user(),
            $request->input('safe_id') ? (int) $request->input('safe_id') : null,
            $request->input('note'),
        );

        return response()->json([
            'message' => 'تم تعديل الفاتورة بنجاح',
            'data'    => $result,
        ]);
    }

    /**
     * PUT /api/admin/invoices/{id}/edit/preview — read-only: runs the exact
     * same rebuild engine editInvoice() uses, then rolls back, so the UI can
     * show old/new totals and per-line prices live as the admin edits,
     * before committing anything.
     */
    public function previewEdit(EditInvoiceRequest $request, string $id)
    {
        $invoice = Invoice::findOrFail($id);
        $result  = $this->salesService->previewEditInvoice($invoice, $request->validated());

        return response()->json([
            'message' => 'ok',
            'data'    => $result,
        ]);
    }
}
