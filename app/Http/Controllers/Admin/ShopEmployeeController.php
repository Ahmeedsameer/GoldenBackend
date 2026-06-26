<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignEmployeeRequest;
use App\Http\Services\ShopService;
use App\Models\Shop;
use App\Models\User;

class ShopEmployeeController extends Controller
{
    public function __construct(private ShopService $shopService) {}

    /**
     * عرض قائمة موظفي الفرع
     */
    public function index(string $shopId)
    {
        $shop = Shop::findOrFail($shopId);
        $perPage = request()->integer('per_page', 20);

        $employees = $this->shopService->listEmployees($shop, $perPage);

        return response()->json([
            'message' => 'تم جلب قائمة الموظفين بنجاح',
            'data'    => $employees,
        ]);
    }

    /**
     * إضافة موظف إلى الفرع
     */
    public function add(AssignEmployeeRequest $request, string $shopId)
    {
        $shop = Shop::findOrFail($shopId);
        $user = User::findOrFail($request->validated('user_id'));

        if ($user->shop_id && (int) $user->shop_id !== (int) $shop->id) {
            return response()->json([
                'message' => 'هذا الموظف منتسب بالفعل إلى فرع آخر، يرجى إزالته منه أولاً',
            ], 422);
        }

        if ((int) $user->shop_id === (int) $shop->id) {
            return response()->json([
                'message' => 'هذا الموظف منتسب بالفعل إلى هذا الفرع',
            ], 422);
        }

        $this->shopService->addEmployee($shop, $user);

        return response()->json([
            'message' => 'تم إضافة الموظف إلى الفرع بنجاح',
            'data'    => [
                'user_id'   => $user->id,
                'user_name' => $user->name,
                'shop_id'   => $shop->id,
                'shop_name' => $shop->name,
            ],
        ], 201);
    }

    /**
     * إزالة موظف من الفرع
     */
    public function remove(string $shopId, string $userId)
    {
        $shop = Shop::findOrFail($shopId);
        $user = User::findOrFail($userId);

        if ((int) $user->shop_id !== (int) $shop->id) {
            return response()->json([
                'message' => 'هذا الموظف لا ينتمي إلى هذا الفرع',
            ], 422);
        }

        $this->shopService->removeEmployee($shop, $user);

        return response()->json([
            'message' => 'تم إزالة الموظف من الفرع بنجاح',
        ]);
    }
}
