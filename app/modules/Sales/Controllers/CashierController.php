<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Safe;
use App\Modules\Sales\Services\SalesService;

class CashierController extends Controller
{
    public function __construct(private SalesService $salesService) {}

    /**
     * Search available goods in the authenticated seller's shop.
     * GET /api/sales/goods?search=قهوة&per_page=20
     */
    public function searchGoods()
    {
        $seller     = auth()->user();
        $search     = request('search');
        $perPage    = request()->integer('per_page', 20);
        $categoryId = request()->integer('category_id') ?: null;

        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }

        $goods = $this->salesService->searchGoods($seller->shop_id, $search, $perPage, $categoryId);

        return response()->json([
            'message' => 'تم جلب البضاعة المتاحة بنجاح',
            'shop_id' => $seller->shop_id,
            'data'    => $goods,
        ]);
    }

    /**
     * Return all categories (for the lookup panel tabs).
     * GET /api/sales/categories
     */
    public function getCategories()
    {
        $categories = Category::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'message' => 'تم جلب الفئات بنجاح',
            'data'    => $categories,
        ]);
    }

    /**
     * Search customers by phone number.
     * GET /api/sales/customers?phone=055&per_page=20
     */
    public function searchCustomers()
    {
        $phone   = request('phone');
        $perPage = request()->integer('per_page', 20);

        $customers = $this->salesService->searchCustomers($phone, $perPage);

        return response()->json([
            'message' => 'تم جلب قائمة العملاء بنجاح',
            'data'    => $customers,
        ]);
    }

    public function searchTesters()
    {
        $search  = request('search');
        $perPage = request()->integer('per_page', 20);

        $testers = $this->salesService->searchTesters($search, $perPage);

        return response()->json([
            'message' => 'تم جلب قائمة تيستيرس بنجاح',
            'data'    => $testers,
        ]);
    }

    /**
     * Return all active currencies (for the currency picker in the cashier UI).
     * GET /api/sales/currencies
     */
    public function getCurrencies()
    {
        $currencies = Currency::where('is_active', true)->orderBy('code')->get();

        return response()->json([
            'message' => 'تم جلب العملات بنجاح',
            'data'    => $currencies,
        ]);
    }

    /**
     * Return all active safes in the seller's shop (for the safe picker in the cashier UI).
     * GET /api/sales/safes
     */
    public function getShopSafes()
    {
        $seller = auth()->user();

        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }

        $safes = Safe::with('safeType')
            ->where('shop_id', $seller->shop_id)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'message' => 'تم جلب خزائن الفرع بنجاح',
            'data'    => $safes,
        ]);
    }
}
