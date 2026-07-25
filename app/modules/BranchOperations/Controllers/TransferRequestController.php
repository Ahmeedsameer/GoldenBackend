<?php

namespace App\Modules\BranchOperations\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\BranchOperations\Models\TransferRequest;
use App\Modules\BranchOperations\Services\TransferRequestService;
use App\Services\WarehouseResolver;
use Illuminate\Http\Request;

class TransferRequestController extends Controller
{
    public function __construct(private TransferRequestService $service, private WarehouseResolver $warehouse) {}

    /**
     * Part 5.1 — inventory ownership determines approval authority, not who
     * requested it. Delegates the actual decision to
     * TransferRequestService::canApproveShop() — the SAME check create() uses
     * to decide whether a creator auto-advances through their own approval —
     * so "who can approve" is defined in exactly one place; this only picks
     * which error message to show.
     */
    private function assertActsForShop(User $user, int $shopId, string $roleLabel): void
    {
        if ($this->service->canApproveShop($shopId, $user)) {
            return;
        }
        abort(403, $this->warehouse->isWarehouse($shopId)
            ? 'هذا الإجراء يقتصر على المدير العام بصفته مدير المستودع الرئيسي'
            : "هذا الإجراء يقتصر على مدير {$roleLabel}");
    }

    private function baseQuery(Request $request)
    {
        $q = TransferRequest::with(['sourceShop:id,name', 'destinationShop:id,name', 'requestedByUser:id,name', 'items.product:id,name,sku,scalar']);

        $user = $request->user();
        // Branch managers and employees (read-only) only see transfers touching their own shop; admin sees all.
        if (in_array($user->role, ['manager', 'sales'], true) && $user->shop_id) {
            $q->where(function ($sq) use ($user) {
                $sq->where('source_shop_id', $user->shop_id)->orWhere('destination_shop_id', $user->shop_id);
            });
        }

        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }
        if ($request->filled('shop_id')) {
            $shopId = (int) $request->get('shop_id');
            $q->where(function ($sq) use ($shopId) {
                $sq->where('source_shop_id', $shopId)->orWhere('destination_shop_id', $shopId);
            });
        }
        // Stock Requests view — every transfer sourced FROM the Main Warehouse
        // (i.e. every Stock Request, whether created via the dedicated
        // storeStockRequest() entry point or an ordinary warehouse-source
        // transfer). Deliberately exact on source only (not "either side"
        // like shop_id above), since a Stock Request is never the warehouse
        // as destination.
        if ($request->boolean('warehouse_source')) {
            $q->where('source_shop_id', $this->warehouse->warehouseShopId());
        }

        return $q;
    }

    public function index(Request $request)
    {
        $perPage = min((int) $request->get('per_page', 25), 100);
        $result = $this->baseQuery($request)->orderByDesc('id')->paginate($perPage);

        return response()->json(['message' => 'ok', 'data' => $result]);
    }

    public function show(Request $request, int $id)
    {
        $transfer = $this->fullReload($request, $id);

        return response()->json(['message' => 'ok', 'data' => $transfer]);
    }

    /**
     * Every mutating action below returns this same fully-hydrated shape —
     * service methods return `fresh()`, which drops previously loaded
     * relations, so the controller re-fetches with all relations every time
     * rather than the frontend silently losing shop/user/log data mid-flow.
     */
    private function fullReload(Request $request, int $id): TransferRequest
    {
        return $this->baseQuery($request)->with(['approvedByUser:id,name', 'logs.user:id,name', 'internalInvoice'])->findOrFail($id);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'source_shop_id' => 'required|integer|exists:shops,id',
            'destination_shop_id' => 'required|integer|exists:shops,id',
            'requested_date' => 'nullable|date',
            'priority' => 'nullable|in:' . implode(',', TransferRequest::PRIORITIES),
            'notes' => 'nullable|string',
            'submit' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.requested_quantity' => 'required|numeric|min:0.001',
        ]);

        $transfer = $this->service->create($data, $request->user(), (bool) ($data['submit'] ?? false));

        // Same single entry point as every other request — create() itself decides (via
        // canApproveShop) whether this creator's own request auto-advanced straight to
        // shipped; the message just reflects whatever actually happened, never a separate flow.
        $message = $transfer->status === TransferRequest::STATUS_SHIPPED
            ? 'تم إنشاء طلب النقل وتنفيذه وشحنه فوراً'
            : 'تم إنشاء طلب النقل بنجاح';

        return response()->json(['message' => $message, 'data' => $transfer], 201);
    }

    /**
     * POST /branch-operations/stock-requests — Branch Manager entry point.
     * NOT a new subsystem: it's a guarded, simplified wrapper around the
     * exact same store()/create() used by ordinary transfers. It hard-locks
     * source_shop_id to the Main Warehouse (a manager can never request stock
     * from anywhere else, and can never pick a different destination — both
     * enforced server-side, not just hidden in the UI) and always submits
     * immediately (a Stock Request has no "draft" concept). Everything after
     * that — the submitted→approved/rejected gate (already admin-only for a
     * warehouse source via canApproveShop()), FIFO on ship/receive, the
     * Internal Transfer Invoice, and every transfer/warehouse report — is the
     * existing TransferRequest machinery, completely untouched.
     */
    public function storeStockRequest(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'manager' || ! $user->shop_id) {
            abort(403, 'طلبات المخزون تُنشأ من قبل مدير الفرع فقط.');
        }

        $data = $request->validate([
            'requested_date' => 'nullable|date',
            'priority'       => 'nullable|in:' . implode(',', TransferRequest::PRIORITIES),
            'notes'          => 'nullable|string',
            'items'          => 'required|array|min:1',
            'items.*.product_id'          => 'required|integer|exists:products,id',
            'items.*.requested_quantity'  => 'required|numeric|min:0.001',
        ]);

        $data['source_shop_id']      = $this->warehouse->warehouseShopId();
        $data['destination_shop_id'] = $user->shop_id;

        $transfer = $this->service->create($data, $user, true);

        return response()->json(['message' => 'تم إرسال طلب المخزون بنجاح، بانتظار موافقة الإدارة', 'data' => $transfer], 201);
    }

    public function submit(Request $request, int $id)
    {
        $transfer = TransferRequest::findOrFail($id);
        $this->service->submit($transfer, $request->user());

        return response()->json(['message' => 'تم إرسال طلب النقل للمراجعة', 'data' => $this->fullReload($request, $id)]);
    }

    public function approve(Request $request, int $id)
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'nullable|array',
            'items.*.item_id' => 'required_with:items|integer|exists:transfer_request_items,id',
            'items.*.approved_quantity' => 'required_with:items|numeric|min:0',
        ]);
        $transfer = TransferRequest::with('items.product')->findOrFail($id);
        $this->assertActsForShop($request->user(), $transfer->source_shop_id, 'فرع المصدر (المُرسِل)');
        $this->service->approve($transfer, $request->user(), $data['notes'] ?? null, $data['items'] ?? null);

        return response()->json(['message' => 'تمت الموافقة على طلب النقل', 'data' => $this->fullReload($request, $id)]);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string']);
        $transfer = TransferRequest::findOrFail($id);
        $this->assertActsForShop($request->user(), $transfer->source_shop_id, 'فرع المصدر (المُرسِل)');
        $this->service->reject($transfer, $request->user(), $data['reason']);

        return response()->json(['message' => 'تم رفض طلب النقل', 'data' => $this->fullReload($request, $id)]);
    }

    public function prepare(Request $request, int $id)
    {
        $transfer = TransferRequest::findOrFail($id);
        $this->assertActsForShop($request->user(), $transfer->source_shop_id, 'فرع المصدر (المُرسِل)');
        $this->service->markPreparing($transfer, $request->user());

        return response()->json(['message' => 'تم تحويل الطلب إلى قيد التجهيز', 'data' => $this->fullReload($request, $id)]);
    }

    public function ship(Request $request, int $id)
    {
        $transfer = TransferRequest::with('items.product')->findOrFail($id);
        $this->assertActsForShop($request->user(), $transfer->source_shop_id, 'فرع المصدر (المُرسِل)');
        $this->service->ship($transfer, $request->user());

        return response()->json(['message' => 'تم شحن طلب النقل', 'data' => $this->fullReload($request, $id)]);
    }

    public function receive(Request $request, int $id)
    {
        $data = $request->validate([
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:transfer_request_items,id',
            'items.*.received_quantity' => 'required|numeric|min:0',
            'items.*.missing_quantity' => 'nullable|numeric|min:0',
            'items.*.damaged_quantity' => 'nullable|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);

        $transfer = TransferRequest::with('items.product', 'items.batches')->findOrFail($id);
        $this->assertActsForShop($request->user(), $transfer->destination_shop_id, 'فرع الوجهة (المُستلِم)');
        $this->service->receive($transfer, $request->user(), $data['items'], $data['notes'] ?? null);

        return response()->json(['message' => 'تم استلام طلب النقل', 'data' => $this->fullReload($request, $id)]);
    }

    /**
     * POST /branch-operations/transfers/{id}/receiving-waste — turns
     * missing_quantity/damaged_quantity already reported at receive() into
     * real WasteRecord rows (see TransferRequestService::registerReceivingWaste
     * for why this never touches Goods). Destination-shop manager only (or
     * admin) — the same authority as receive() itself, since this is a
     * direct follow-up to that action.
     */
    public function registerReceivingWaste(Request $request, int $id)
    {
        $data = $request->validate([
            'entries' => 'required|array|min:1',
            'entries.*.item_id' => 'required|integer|exists:transfer_request_items,id',
            'entries.*.reason' => 'required|in:' . implode(',', \App\Modules\BranchOperations\Models\WasteRecord::REASONS),
            'entries.*.notes' => 'nullable|string',
        ]);

        $transfer = TransferRequest::with('items.product', 'items.batches')->findOrFail($id);
        $this->assertActsForShop($request->user(), $transfer->destination_shop_id, 'فرع الوجهة (المُستلِم)');
        $this->service->registerReceivingWaste($transfer, $request->user(), $data['entries']);

        return response()->json(['message' => 'تم تسجيل الهالك بنجاح', 'data' => $this->fullReload($request, $id)]);
    }

    public function close(Request $request, int $id)
    {
        $transfer = TransferRequest::findOrFail($id);
        $user = $request->user();
        // Closing is bookkeeping only (no inventory movement) — either side's manager or admin may do it.
        if ($user->role !== 'admin' && (int) $user->shop_id !== $transfer->source_shop_id && (int) $user->shop_id !== $transfer->destination_shop_id) {
            abort(403, 'هذا الإجراء يقتصر على مدير فرع المصدر أو الوجهة');
        }
        $this->service->close($transfer, $user);

        return response()->json(['message' => 'تم إغلاق طلب النقل', 'data' => $this->fullReload($request, $id)]);
    }

    /** Admin-only override — cancel a transfer before it ships (Part 5.1). */
    public function cancel(Request $request, int $id)
    {
        $data = $request->validate(['reason' => 'required|string']);
        $transfer = TransferRequest::findOrFail($id);
        $this->service->cancel($transfer, $request->user(), $data['reason']);

        return response()->json(['message' => 'تم إلغاء طلب النقل', 'data' => $this->fullReload($request, $id)]);
    }

    /** GET /branch-operations/transfers/available-stock?product_id=&shop_id= — used by the request-builder form. */
    public function availableStock(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'shop_id' => 'required|integer|exists:shops,id',
        ]);

        return response()->json(['message' => 'ok', 'data' => [
            'available' => $this->service->availableStock((int) $data['product_id'], (int) $data['shop_id']),
        ]]);
    }
}
