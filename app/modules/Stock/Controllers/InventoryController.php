<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Goods;
use App\Modules\Stock\Requests\TransferRequest;
use App\Modules\Stock\Services\TransferService;

class InventoryController extends Controller
{
    public function __construct(private TransferService $transferService) {}

    /**
     * استعراض المخزون الحالي.
     *
     * ?shop_id=3   → بضاعة الفرع رقم 3
     * (بدون shop_id) → المستودع الرئيسي
     * ?search=قهوة  → بحث باسم المنتج
     */
    public function index()
    {
        $perPage = request()->integer('per_page', 20);
        $shopId  = request('shop_id');
        $search  = request('search');

        $query = Goods::query()
            ->with([
                'shop:id,name,address',
                'supplyItem.product:id,name,sku,scalar',
                'supplyItem.supply:id,date,payment_method',
            ])
            ->where('current_quantity', '>', 0);

        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        } else {
            $query->whereNull('shop_id');
        }

        if ($search) {
            $query->whereHas('supplyItem.product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $location = $shopId ? 'الفرع رقم ' . $shopId : 'المستودع الرئيسي';

        return response()->json([
            'message'  => 'تم جلب مخزون ' . $location . ' بنجاح',
            'location' => $shopId ? 'shop' : 'main_stock',
            'shop_id'  => $shopId ? (int) $shopId : null,
            'data'     => $query->paginate($perPage),
        ]);
    }

    /**
     * نقل بضاعة بين المستودع والفروع أو بين فرعين.
     *
     * Body:
     *   goods_id   : id سجل البضاعة المصدر
     *   quantity   : الكمية المراد نقلها
     *   to_shop_id : null = مستودع رئيسي ، رقم = فرع مستهدف
     */
    public function transfer(TransferRequest $request)
    {
        $source   = Goods::findOrFail($request->validated('goods_id'));
        $quantity = (float) $request->validated('quantity');
        $toShopId = $request->validated('to_shop_id');

        $result = $this->transferService->transfer($source, $quantity, $toShopId);

        $from = $source->shop_id === null
            ? 'المستودع الرئيسي'
            : 'الفرع رقم ' . $source->shop_id;

        $to = $toShopId === null
            ? 'المستودع الرئيسي'
            : 'الفرع رقم ' . $toShopId;

        return response()->json([
            'message'     => "تم نقل {$quantity} وحدة من {$from} إلى {$to} بنجاح",
            'source'      => $result['source'],
            'destination' => $result['destination'],
        ]);
    }
}
