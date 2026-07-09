<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Sales\Requests\StoreInvoiceRequest;
use App\Modules\Sales\Requests\UpdateInvoiceStatusRequest;
use App\Modules\Sales\Services\SalesService;

class InvoiceController extends Controller
{
    public function __construct(private SalesService $salesService) {}

    /**
     * GET /api/sales/invoices?status=pending&date_from=2026-01-01&date_to=2026-12-31
     */
    public function index()
    {
        $filters = request()->only(['status', 'date_from', 'date_to']);
        $perPage = request()->integer('per_page', 15);

        $invoices = $this->salesService->getSellerInvoices(
            auth()->id(),
            $filters,
            $perPage
        );

        return response()->json([
            'message' => 'تم جلب سجل الفواتير بنجاح',
            'data'    => $invoices,
        ]);
    }

    /**
     * GET /api/sales/invoices/{id}
     */
    public function show(string $id)
    {
        $invoice = Invoice::with([
            'customer',
            'seller:id,name',
            'shop:id,name',
            'items.product:id,name,sku,scalar',
            'items.goods',
            'payments.currency:id,code,symbol',
        ])->where('seller_id', auth()->id())->findOrFail($id);

        return response()->json([
            'message' => 'تم جلب الفاتورة بنجاح',
            'data'    => $invoice,
        ]);
    }

    /**
     * POST /api/sales/invoices
     *
     * Response includes a `violations` array when any item's price is below
     * its category's minimum_sell_price. The invoice is always created with
     * status "pending" so a tester/manager must review it before approval.
     */
    public function store(StoreInvoiceRequest $request)
    {
        $result = $this->salesService->createInvoice($request->validated());

        $hasViolations = ! empty($result['violations']);

        $message = $hasViolations
            ? 'تم إنشاء الفاتورة وخصم المخزون بنجاح، ولكن بعض الأسعار أقل من الحد الأدنى المسموح به — الفاتورة بانتظار المراجعة'
            : 'تم إنشاء الفاتورة وخصم المخزون بنجاح';

        return response()->json([
            'message'    => $message,
            'violations' => $result['violations'],
            'data'       => $result['invoice'],
        ], 201);
    }

    /**
     * PUT /api/sales/invoices/{id}/status
     */
    public function updateStatus(UpdateInvoiceStatusRequest $request, string $id)
    {
        $invoice = Invoice::where('seller_id', auth()->id())->findOrFail($id);
        $updated = $this->salesService->updateStatus($invoice, $request->validated('status'));

        return response()->json([
            'message' => 'تم تحديث حالة الفاتورة بنجاح',
            'data'    => $updated,
        ]);
    }
}
