<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Safe;
use App\Modules\Sales\Requests\EditInvoiceRequest;
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

    /**
     * PUT /api/manager/invoices/{id}/edit — same as the admin edit, but both
     * the invoice AND the chosen settlement safe must belong to the manager's
     * own branch (never a shop they merely pick).
     */
    public function edit(EditInvoiceRequest $request, string $id)
    {
        $invoice = Invoice::where('shop_id', $request->user()->shop_id)->findOrFail($id);

        $safeId = $request->input('safe_id') ? (int) $request->input('safe_id') : null;
        if ($safeId !== null) {
            Safe::where('shop_id', $request->user()->shop_id)->findOrFail($safeId);
        }

        $result = $this->salesService->editInvoice(
            $invoice,
            $request->validated(),
            $request->user(),
            $safeId,
            $request->input('note'),
        );

        return response()->json([
            'message' => 'تم تعديل الفاتورة بنجاح',
            'data'    => $result,
        ]);
    }

    /**
     * PUT /api/manager/invoices/{id}/edit/preview — read-only, own branch
     * only (same shop_id guard as edit()). Runs the same rebuild engine then
     * rolls back — never mutates stock/pricing/safe balances.
     */
    public function previewEdit(EditInvoiceRequest $request, string $id)
    {
        $invoice = Invoice::where('shop_id', $request->user()->shop_id)->findOrFail($id);
        $result  = $this->salesService->previewEditInvoice($invoice, $request->validated());

        return response()->json([
            'message' => 'ok',
            'data'    => $result,
        ]);
    }
}
