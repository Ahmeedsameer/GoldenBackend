<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Sales\Requests\UpdateInvoiceStatusRequest;
use App\Modules\Sales\Services\SalesService;

/**
 * Manager review of pending invoices for their OWN shop only — the same
 * "sold below category minimum" queue the Admin reviews shop-wide under
 * /admin/invoices, just scoped to the manager's branch. This replaces the
 * older cache-based per-line override-request flow, which only ever fired
 * under the legacy "Manual Total" pricing mode and is effectively dead under
 * today's default per-item pricing engine (see SalesService::createInvoice()
 * — a below-minimum sale is saved straight to the invoices table with
 * status='pending', not to cache).
 */
class ManagerOverrideController extends Controller
{
    public function __construct(private SalesService $salesService) {}

    /**
     * GET /api/manager/override-requests
     * Lists pending invoices for the manager's own shop only — shop_id is
     * always taken from the authenticated manager, never from the client.
     */
    public function index()
    {
        $manager = auth()->user();

        $invoices = $this->salesService->getInvoicesForAdmin([
            'status'  => 'pending',
            'shop_id' => $manager->shop_id,
        ], request()->integer('per_page', 15));

        return response()->json([
            'message' => 'تم جلب الفواتير المعلّقة بنجاح',
            'data'    => $invoices,
        ]);
    }

    /**
     * PUT /api/manager/override-requests/{id}
     * Manager approves or rejects a pending invoice from their OWN shop only.
     */
    public function respond(UpdateInvoiceStatusRequest $request, string $id)
    {
        $manager = auth()->user();
        $invoice = Invoice::findOrFail($id);

        if ((int) $invoice->shop_id !== (int) $manager->shop_id) {
            return response()->json(['message' => 'غير مصرح لك بإدارة فاتورة من فرع آخر'], 403);
        }

        $updated = $this->salesService->updateStatus($invoice, $request->validated('status'));

        $label = $updated->status === 'approved' ? 'اعتماد' : 'رفض';

        return response()->json([
            'message' => "تم {$label} الفاتورة بنجاح",
            'data'    => $updated,
        ]);
    }
}
