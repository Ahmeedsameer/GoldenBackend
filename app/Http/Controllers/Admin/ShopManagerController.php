<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignManagerRequest;
use App\Http\Resources\ShopResource;
use App\Http\Services\ShopService;
use App\Models\Shop;
use App\Models\User;

class ShopManagerController extends Controller
{
    public function __construct(private ShopService $shopService) {}

    /**
     * تعيين مدير للفرع
     */
    public function assign(AssignManagerRequest $request, string $shopId)
    {
        $shop = Shop::findOrFail($shopId);
        $user = User::findOrFail($request->validated('user_id'));

        // تحقق أن المستخدم ليس مسؤولاً عن فرع آخر مسبقاً
        if ($user->managedShop && (int) $user->managedShop->id !== (int) $shop->id) {
            return response()->json([
                'message' => 'هذا المستخدم مدير لفرع آخر بالفعل، يرجى إلغاء تعيينه منه أولاً',
            ], 422);
        }

        $updated = $this->shopService->assignManager($shop, $user);

        return response()->json([
            'message' => 'تم تعيين المدير للفرع بنجاح',
            'data'    => new ShopResource($updated),
        ]);
    }

    /**
     * إزالة المدير من الفرع
     */
    public function remove(string $shopId)
    {
        $shop = Shop::findOrFail($shopId);

        if (!$shop->manager_id) {
            return response()->json([
                'message' => 'لا يوجد مدير معيّن لهذا الفرع',
            ], 422);
        }

        $updated = $this->shopService->removeManager($shop);

        return response()->json([
            'message' => 'تم إزالة المدير من الفرع بنجاح',
            'data'    => new ShopResource($updated),
        ]);
    }
}
