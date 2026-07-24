<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompoundRequest;
use App\Http\Requests\StorePackagingRequest;
use App\Http\Requests\StoreRawMaterialRequest;
use App\Http\Requests\StoreReadyProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Services\ProductDetailService;
use App\Http\Services\ProductService;
use App\Models\Product;
use Illuminate\Http\Request;
use Log;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */






    public function __construct(
        private ProductService $productService,
        private ProductDetailService $productDetailService,
        private \App\Services\Reports\ReportExportService $reportExportService,
    ) {

    }

    // ── Product Details page ────────────────────────────────────────────────

    public function detail(int $id)
    {
        $product = Product::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->productDetailService->summary($product)]);
    }

    public function purchaseHistory(int $id, Request $request)
    {
        $product = Product::findOrFail($id);
        $perPage = min((int) $request->get('per_page', 15), 100);
        $filters = $request->only(['search', 'sort', 'direction']);

        return response()->json(['message' => 'ok', 'data' => $this->productDetailService->purchaseHistory($product, $filters, $perPage)]);
    }

    public function movements(int $id, Request $request)
    {
        $product = Product::findOrFail($id);
        $perPage = min((int) $request->get('per_page', 15), 100);
        $page = (int) $request->get('page', 1);

        return response()->json(['message' => 'ok', 'data' => $this->productDetailService->movements($product, $perPage, $page)]);
    }

    public function supplierHistory(int $id)
    {
        $product = Product::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->productDetailService->supplierHistory($product)]);
    }

    /** GET /api/products/{id}/purchase-history/export?format=pdf|excel — every row, unpaginated. */
    public function exportPurchaseHistory(int $id, Request $request)
    {
        $product = Product::findOrFail($id);
        $filters = $request->only(['search', 'sort', 'direction']);
        $all = $this->productDetailService->purchaseHistory($product, $filters, 100000);

        $columns = ['التاريخ', 'المورد', 'رقم التوريد', 'الكمية', 'سعر الشراء', 'الإجمالي', 'الفرع'];
        $rows = collect($all->items())->map(fn ($r) => [
            $r->date, $r->supplier_name, '#' . $r->supply_id, $r->quantity, $r->unit_price, $r->total_cost, $r->branch_name,
        ])->all();

        $title = 'سجل مشتريات ' . $product->name;
        $filtersApplied = array_filter(['بحث' => $filters['search'] ?? null]);

        return $request->input('format') === 'excel'
            ? $this->reportExportService->excel($title, $columns, $rows, $filtersApplied, [4, 5])
            : $this->reportExportService->pdf($title, $columns, $rows, $filtersApplied);
    }

    public function analytics(int $id)
    {
        $product = Product::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->productDetailService->analytics($product)]);
    }

    public function index()
    {
        $query = Product::query();

        // Accept the modern `search` param (used by the Supply screen and others)
        // and the legacy `name` param. Search matches product name, SKU or
        // barcode so any eligible product is findable regardless of what the
        // user types. There are no hidden filters (no is_active / soft-delete /
        // type gating) — every product is searchable unless `active_only` is set.
        // Single reusable matcher (name / sku / barcode, case-insensitive, partial).
        $query->search(request('search', request('name')));

        if (request()->boolean('active_only')) {
            $query->where('is_active', true);
        }

        // Opt-in filters — default behavior (every product, every type) is
        // unchanged for existing callers (Product Management, etc).
        if ($type = request('product_type')) {
            $query->where('product_type', $type);
        }
        if ($excludeType = request('exclude_type')) {
            // Supply/Purchasing use this to hide Compound Products — they are
            // virtual (composed at sale time) and have no inventory to buy.
            $query->where(function ($q) use ($excludeType) {
                $q->where('product_type', '!=', $excludeType)->orWhereNull('product_type');
            });
        }

        // Support both `per_page` (modern) and `limit` (legacy). Capped at 100
        // so a single response stays small; clients paginate for more.
        $perPage = max(1, min((int) request('per_page', request('limit', 30)), 100));

        // Newest first so a just-created product is immediately at the top.
        $products = $query->with('category.productType', 'defaultOil:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * GET /api/products/oil-options — admin Product Management screen's
     * "Default Oil" picker for Composite Products. Same "what counts as an
     * oil" definition as SalesService::searchOilProducts() (Raw Material +
     * category priced per-gram), but not shop-scoped/stock-decorated since
     * this is a catalog reference picker, not a point-of-sale stock lookup.
     */
    public function oilOptions()
    {
        $products = Product::query()
            ->where('is_active', true)
            ->where('product_type', Product::TYPE_RAW_MATERIAL)
            ->whereHas('category.productType', fn ($q) => $q->where('pricing_source', 'category'))
            ->search(request('search'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'sku']);

        return response()->json(['message' => 'ok', 'data' => $products]);
    }

    // ── Four independent Product Creation Forms ────────────────────────────
    // Each has its own request class (own fields, own validation) and its own
    // frontend page/component — never the shared generic form. All four still
    // reuse ProductService::create() (same category-threshold defaults, SKU
    // auto-generation, and Product-Type-driven scalar/pricing rules).

    public function storeRawMaterial(StoreRawMaterialRequest $request)
    {
        $this->productService->create($request);

        return response()->json(['message' => 'تم إنشاء المادة الخام بنجاح'], 201);
    }

    public function storePackaging(StorePackagingRequest $request)
    {
        $this->productService->create($request);

        return response()->json(['message' => 'تم إنشاء منتج التغليف بنجاح'], 201);
    }

    public function storeReadyProduct(StoreReadyProductRequest $request)
    {
        $this->productService->create($request);

        return response()->json(['message' => 'تم إنشاء المنتج الجاهز بنجاح'], 201);
    }

    public function storeCompound(StoreCompoundRequest $request)
    {
        $this->productService->create($request);

        return response()->json(['message' => 'تم إنشاء العطر المركّب بنجاح'], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $product = Product::with('category.productType', 'defaultOil:id,name')->findOrFail($id);

        return new ProductResource($product);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, string $id)

    {
        Log::info('Updating product ', ['data' => $request->all(), 'id' => $id]);
        
        $this->productService->update($request,$id);

        return response()->json(['message'=> 'تم تحديث المنتج بنجاح'], 200);   
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
