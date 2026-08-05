<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Currency;
use App\Models\PaymentMethod;
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

        // Follow the seller's ACTIVE branch (primary, or a live temporary transfer).
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;
        $goods  = $this->salesService->searchGoods($shopId, $search, $perPage, $categoryId);

        return response()->json([
            'message' => 'تم جلب البضاعة المتاحة بنجاح',
            'shop_id' => $seller->shop_id,
            'data'    => $goods,
        ]);
    }

    /**
     * Barcode-scanner lookup — GET /api/sales/goods/barcode?code=1234567890123
     * EXACT match only (barcode, then SKU), never product name — see
     * SalesService::findGoodsByCode(). Distinct 'ambiguous' vs 'not_found'
     * statuses so the frontend can show the right message for each.
     */
    public function findGoodsByBarcode()
    {
        $seller = auth()->user();
        $code   = (string) request('code', '');

        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        if (trim($code) === '') {
            return response()->json(['message' => 'الرجاء إدخال الباركود', 'status' => 'not_found', 'data' => null], 422);
        }

        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;
        $result = $this->salesService->findGoodsByCode($shopId, $code);

        return response()->json([
            'message' => 'ok',
            'status'  => $result['status'],
            'data'    => $result['goods'],
        ]);
    }

    /**
     * Products that have a saved recipe — for the compose modal's recipe search.
     * GET /api/sales/composable-products?search=عطر
     */
    public function composableProducts()
    {
        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->searchComposableProducts(request('search')),
        ]);
    }

    /**
     * Catalog (finished/sellable) products — show_in_catalog=true with a saved
     * recipe. Powers the new "sell a perfume" browser, separate from the
     * generic add-item search so raw materials never surface here.
     * GET /api/sales/catalog-products?search=Sauvage
     */
    public function catalogProducts()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->searchCatalogProducts($shopId, request('search')),
        ]);
    }

    /**
     * Raw materials priced as "oil" (per gram) — Product Builder Step 1.
     * The seller is completely free to pick any of these; no predefined recipe.
     * GET /api/sales/oil-products?search=Royal
     */
    public function oilProducts()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->searchOilProducts($shopId, request('search')),
        ]);
    }

    /**
     * Packaging bottles (capacity_ml required) — Product Builder Step 3.
     * GET /api/sales/bottle-products?search=30
     */
    public function bottleProducts()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->searchBottleProducts($shopId, request('search')),
        ]);
    }

    /**
     * Raw materials in the dedicated "كحول" category — Product Builder's
     * Alcohol picker, chosen exactly like the Oil picker (freely, every sale).
     * GET /api/sales/alcohol-products?search=96
     */
    public function alcoholProducts()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->searchAlcoholProducts($shopId, request('search')),
        ]);
    }

    /**
     * Product Builder live price calculation + bottle-capacity validation.
     * GET /api/sales/compound-price?catalog_product_id=&oil_product_id=&oil_qty=&bottle_product_id=&alcohol_product_id=&alcohol_qty=&quantity=
     */
    public function compoundPrice()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        $data = request()->validate([
            'catalog_product_id' => ['required', 'integer', 'exists:products,id'],
            'oil_product_id'     => ['required', 'integer', 'exists:products,id'],
            'oil_qty'            => ['required', 'numeric', 'min:0.001'],
            'bottle_product_id'  => ['required', 'integer', 'exists:products,id'],
            // Alcohol is optional — the cashier picks bottle+oil first, the alcohol
            // quantity suggestion (and product choice) comes after.
            'alcohol_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'alcohol_qty'        => ['nullable', 'numeric', 'min:0.001'],
            // Manufacturing Quantity — how many identical bottles this operation
            // produces. Defaults to 1 (unchanged behavior for every existing caller).
            'quantity'           => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->salesService->calculateCompoundPrice(
            $shopId,
            (int) $data['catalog_product_id'],
            (int) $data['oil_product_id'],
            (float) $data['oil_qty'],
            (int) $data['bottle_product_id'],
            isset($data['alcohol_product_id']) ? (int) $data['alcohol_product_id'] : null,
            isset($data['alcohol_qty']) ? (float) $data['alcohol_qty'] : null,
            isset($data['quantity']) ? (int) $data['quantity'] : 1,
        );

        return response()->json(['message' => 'ok', 'data' => $result]);
    }

    /**
     * A product's recipe (BOM) components, resolved for the seller's shop
     * (price + unit + available stock) so the compose modal can load them.
     * GET /api/sales/products/{id}/components
     */
    public function productComponents(string $id)
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }

        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->resolveProductComponents($seller->shop_id, (int) $id),
        ]);
    }

    /**
     * Catalog products matching the search that are NOT stocked in the seller's
     * shop. Powers the cashier's "product exists but not in this branch" hint.
     * GET /api/sales/unstocked-products?search=قهوة
     */
    public function unstockedProducts()
    {
        $seller = auth()->user();
        $search = request('search');

        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }

        $products = $this->salesService->searchUnstockedProducts($seller->shop_id, $search);

        return response()->json([
            'message' => 'ok',
            'data'    => $products,
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
     * Search customers by name OR phone.
     * GET /api/sales/customers?search=محمد&per_page=20
     */
    public function searchCustomers()
    {
        // 'search' is the real param; 'phone' kept as a fallback for any
        // caller still on the old phone-only contract.
        $term    = request('search', request('phone'));
        $perPage = request()->integer('per_page', 20);

        $customers = $this->salesService->searchCustomers($term, $perPage);

        return response()->json([
            'message' => 'تم جلب قائمة العملاء بنجاح',
            'data'    => $customers,
        ]);
    }

    /**
     * Quick-create a customer from inside the cashier, without submitting an
     * invoice — reuses the exact same firstOrCreate-by-phone rule the normal
     * checkout flow already uses (see SalesService::createCustomer()).
     * POST /api/sales/customers { name, phone, email?, address? }
     */
    public function createCustomer()
    {
        $data = request()->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|string|max:32',
            'email'   => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);

        $seller = auth()->user();
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        $customer = $this->salesService->createCustomer($data, $shopId);

        return response()->json([
            'message' => 'تم حفظ بيانات العميل بنجاح',
            'data'    => $customer,
        ], 201);
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
     * Return all active payment methods (for the payment-method picker in the cashier UI).
     * GET /api/sales/payment-methods
     */
    public function getPaymentMethods()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        // Branch restriction (Payment Methods Phase 2): a method with no shops
        // attached is unrestricted (available everywhere) — the existing default.
        $methods = PaymentMethod::with('currency:id,code,symbol')
            ->where('is_active', true)
            ->where(fn ($q) => $q->doesntHave('shops')->orWhereHas('shops', fn ($sq) => $sq->where('shops.id', $shopId)))
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'تم جلب وسائل الدفع بنجاح',
            'data'    => $methods,
        ]);
    }

    /**
     * Live cost/profit preview for the cart, BEFORE the sale is finalized.
     * Reuses the exact same FIFO batch order SalesService::processItem() drains
     * from at sale time (SalesService::fifoBatchesQuery()) — same cost figures,
     * just computed read-only ahead of the actual sale.
     * POST /api/sales/quote-cost  { items: [{product_id, quantity}] }
     */
    public function quoteCost()
    {
        $seller = auth()->user();
        if (! $seller->shop_id) {
            return response()->json(['message' => 'البائع غير مرتبط بأي فرع'], 422);
        }
        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;

        $items = collect(request('items', []))
            ->filter(fn ($i) => !empty($i['product_id']) && (float) ($i['quantity'] ?? 0) > 0)
            ->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (float) $i['quantity']])
            ->values()->all();

        return response()->json([
            'message' => 'ok',
            'data'    => $this->salesService->quoteCartCost($shopId, $items),
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

        $shopId = app(\App\Modules\Hr\Services\ActiveBranchService::class)->activeBranchId($seller) ?? (int) $seller->shop_id;
        $safes  = Safe::with('safeType')
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'message' => 'تم جلب خزائن الفرع بنجاح',
            'data'    => $safes,
        ]);
    }
}
