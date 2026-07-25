<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Goods;
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
}
