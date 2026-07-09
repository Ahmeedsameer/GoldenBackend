<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Goods;
use App\Models\Shop;
use App\Modules\Stock\Services\TransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ManagerInventoryController extends Controller
{
    public function __construct(private TransferService $transferService) {}

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/inventory
    // Returns the authenticated manager's shop inventory (non-zero stock).
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
        $goods   = $query->orderByDesc('current_quantity')->paginate($perPage);

        return response()->json([
            'message' => 'تم جلب المخزون بنجاح',
            'data'    => $goods,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // GET /api/manager/shops
    // Returns all OTHER active shops (for transfer destination dropdown).
    // Also includes null entry representing the main warehouse.
    // ────────────────────────────────────────────────────────────────
    public function shops()
    {
        $myShopId = Auth::user()->shop_id;

        $shops = Shop::where('id', '!=', $myShopId)
            ->get(['id', 'name']);

        return response()->json([
            'message' => 'ok',
            'data'    => $shops,
        ]);
    }

    // ────────────────────────────────────────────────────────────────
    // POST /api/manager/inventory/transfer
    // Body: { goods_id, quantity, to_shop_id (nullable = main warehouse) }
    // Manager can only transfer FROM their own shop.
    // ────────────────────────────────────────────────────────────────
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'goods_id'   => ['required', 'integer', 'exists:goods,id'],
            'quantity'   => ['required', 'numeric', 'gt:0'],
            'to_shop_id' => ['nullable', 'integer', 'exists:shops,id'],
        ], [
            'goods_id.required'  => 'يرجى تحديد الصنف المراد نقله.',
            'goods_id.exists'    => 'الصنف المحدد غير موجود.',
            'quantity.required'  => 'يرجى إدخال الكمية المراد نقلها.',
            'quantity.gt'        => 'يجب أن تكون الكمية أكبر من صفر.',
            'to_shop_id.exists'  => 'الفرع المستهدف غير موجود.',
        ]);

        $source = Goods::with('supplyItem.product')->findOrFail($validated['goods_id']);

        // Security: manager may only transfer from their own shop
        if ((int) $source->shop_id !== (int) Auth::user()->shop_id) {
            return response()->json([
                'message' => 'غير مصرح بنقل مخزون من فرع آخر.',
            ], 403);
        }

        try {
            $result = $this->transferService->transfer(
                $source,
                (float) $validated['quantity'],
                isset($validated['to_shop_id']) ? (int) $validated['to_shop_id'] : null,
            );

            return response()->json([
                'message' => 'تم نقل المخزون بنجاح.',
                'data'    => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
