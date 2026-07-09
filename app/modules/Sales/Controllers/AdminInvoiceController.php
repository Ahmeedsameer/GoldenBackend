<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
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
            'items.product:id,name,sku,scalar,category_id',
            'items.product.category:id,name,minimum_sell_price',
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
}
