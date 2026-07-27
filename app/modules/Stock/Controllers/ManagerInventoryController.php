<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Goods;
use App\Modules\BranchOperations\Models\TransferRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * View-only: a manager can see their own branch's stock, but can never move
 * it directly — the only legitimate way stock enters/leaves a branch is the
 * Stock Request / Transfer Request workflow (see
 * App\Modules\BranchOperations\Controllers\TransferRequestController). The
 * old instant "manager inventory transfer" endpoint (and its destination-
 * shops lookup) has been removed on purpose, not merely hidden.
 */
class ManagerInventoryController extends Controller
{
    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/inventory
    // Returns the authenticated manager's shop inventory as individual FIFO
    // batches (each Goods row = one batch, its own `date`) — the frontend
    // uses `date` to badge recently-added vs long-sitting stock.
    // Supports ?search= and ?per_page=
    // ────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $shopId = Auth::user()->shop_id;

        $query = Goods::where('shop_id', $shopId)
            ->where('current_quantity', '>', 0)
            ->with(['supplyItem.product.category']);

        if ($search = $request->get('search')) {
            // Reuse the single Product::scopeSearch matcher (name / sku / barcode).
            $query->whereHas('supplyItem.product', fn ($q) => $q->search($search));
        }

        $perPage = (int) $request->get('per_page', 30);
        $goods   = $query->orderBy('date')->orderBy('id')->paginate($perPage);

        return response()->json([
            'message' => 'تم جلب المخزون بنجاح',
            'data'    => $goods,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/inventory/{productId}/history
    // A branch never receives stock any other way than a received Transfer
    // Request (Supplies always land in the Main Warehouse — shop_id null —
    // see SupplyService::create()), so every incoming unit this shop ever
    // got for this product is fully reconstructable from TransferRequestItem
    // rows whose transfer's destination is this shop. Answers exactly what
    // the manager asks when they open a product: how much was ever sent to
    // them, how much of that is left right now, and — per shipment — how
    // much and when, so the newest delivery is obvious.
    // ────────────────────────────────────────────────────────────────
    public function productHistory(Request $request, int $productId)
    {
        $shopId = Auth::user()->shop_id;

        $currentQuantity = (float) Goods::where('shop_id', $shopId)
            ->whereHas('supplyItem', fn ($q) => $q->where('product_id', $productId))
            ->sum('current_quantity');

        $shipments = TransferRequestItem::query()
            ->where('product_id', $productId)
            ->whereHas('transferRequest', fn ($q) => $q->where('destination_shop_id', $shopId)->whereIn('status', ['received', 'closed']))
            ->with(['transferRequest:id,request_number,source_shop_id,status,received_at', 'transferRequest.sourceShop:id,name'])
            ->get()
            ->map(fn (TransferRequestItem $item) => [
                'transfer_request_id' => $item->transfer_request_id,
                'request_number' => $item->transferRequest->request_number,
                'source_shop' => $item->transferRequest->sourceShop->name ?? null,
                'received_quantity' => (float) $item->received_quantity,
                'missing_quantity' => (float) $item->missing_quantity,
                'damaged_quantity' => (float) $item->damaged_quantity,
                'received_at' => optional($item->transferRequest->received_at)->toDateTimeString(),
            ])
            ->sortByDesc('received_at')
            ->values();

        return response()->json(['message' => 'ok', 'data' => [
            'product_id' => $productId,
            'current_quantity' => round($currentQuantity, 3),
            'total_received' => round((float) $shipments->sum('received_quantity'), 3),
            'shipments' => $shipments,
        ]]);
    }
}
