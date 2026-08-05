<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Http\Request;

/**
 * Branch-wide invoice visibility + cancel for a branch manager — unlike
 * InvoiceController (seller-scoped: `where('seller_id', auth()->id())`,
 * meant for a seller reviewing only their own sales), a manager needs to
 * see and act on every sale made in THEIR shop, regardless of which seller
 * rang it up. Always force-scoped to the manager's own shop_id — never a
 * shop they merely pick, same rule Part 5.8 already applies to Transfer
 * Requests.
 */
class ManagerInvoiceController extends Controller
{
    public function __construct(private SalesService $salesService) {}

    /** GET /api/manager/invoices?status=&date_from=&date_to= */
    public function index(Request $request)
    {
        $filters = [
            'status'    => $request->get('status'),
            'shop_id'   => $request->user()->shop_id,
            'date_from' => $request->get('date_from'),
            'date_to'   => $request->get('date_to'),
            'search'    => $request->get('search'),
        ];
        $invoices = $this->salesService->getInvoicesForAdmin($filters, $request->integer('per_page', 15));

        return response()->json(['message' => 'ok', 'data' => $invoices]);
    }

    /** GET /api/manager/invoices/{id} */
    public function show(Request $request, string $id)
    {
        $invoice = Invoice::with([
            'customer',
            'seller:id,name',
            'shop:id,name',
            'items.product:id,name,sku,scalar,purchase_cost',
            'items.parentProduct:id,name',
            'items.goods.supplyItem:id,unit_price',
            'payments.currency:id,code,symbol',
        ])->where('shop_id', $request->user()->shop_id)->findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $invoice]);
    }

    /** POST /api/manager/invoices/{id}/cancel — reverses stock AND money (see SalesService::cancel). */
    public function cancel(Request $request, string $id)
    {
        $data = $request->validate(['reason' => 'nullable|string']);
        $invoice = Invoice::where('shop_id', $request->user()->shop_id)->findOrFail($id);
        $cancelled = $this->salesService->cancel($invoice, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'message' => 'تم إلغاء الفاتورة بنجاح، وتمت إعادة المخزون واسترجاع المبلغ',
            'data'    => $cancelled,
        ]);
    }
}
