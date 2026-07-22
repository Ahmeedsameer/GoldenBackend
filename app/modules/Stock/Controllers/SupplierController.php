<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Modules\Stock\Requests\StoreSupplierRequest;
use App\Modules\Stock\Requests\UpdateSupplierRequest;
use App\Modules\Stock\Services\SupplierProfileService;
use App\Modules\Stock\Services\SupplierService;

class SupplierController extends Controller
{
    public function __construct(
        private SupplierService $supplierService,
        private SupplierProfileService $profileService,
    ) {}

    // ── Supplier Profile page ───────────────────────────────────────────────

    public function profile(string $id)
    {
        $supplier = Supplier::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->profileService->profile($supplier)]);
    }

    public function profileProducts(string $id)
    {
        $supplier = Supplier::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->profileService->products($supplier)]);
    }

    public function profileAnalytics(string $id)
    {
        $supplier = Supplier::findOrFail($id);

        return response()->json(['message' => 'ok', 'data' => $this->profileService->analytics($supplier)]);
    }

    public function insights()
    {
        return response()->json(['message' => 'ok', 'data' => $this->profileService->globalInsights()]);
    }

    /** GET /api/stock/suppliers/{id}/profile/export?format=pdf|excel — every product supplied, grouped by type. */
    public function exportProfile(string $id, \App\Services\Reports\ReportExportService $exportService)
    {
        $supplier = Supplier::findOrFail($id);
        $grouped = $this->profileService->products($supplier);
        $typeLabel = fn (string $t) => ['RAW_MATERIAL' => 'خامات', 'PACKAGING' => 'مستلزمات تعبئة', 'READY_PRODUCT' => 'منتجات جاهزة'][$t] ?? $t;

        $columns = ['الفئة', 'المنتج', 'الكود', 'عدد المشتريات', 'الكمية الإجمالية', 'متوسط السعر', 'آخر شراء'];
        $rows = [];
        foreach ($grouped as $type => $products) {
            foreach ($products as $p) {
                $rows[] = [$typeLabel($type), $p['name'], $p['sku'], $p['purchase_count'], $p['total_purchased_quantity'], $p['average_purchase_price'], $p['last_purchased_at']];
            }
        }

        $title = 'المنتجات المورَّدة — ' . $supplier->name;

        return request()->input('format') === 'excel'
            ? $exportService->excel($title, $columns, $rows, [], [4, 5])
            : $exportService->pdf($title, $columns, $rows);
    }

    public function index()
    {
        $filters = request()->only(['search']);
        $perPage = request()->integer('per_page', 15);

        return response()->json(
            $this->supplierService->getAll($filters, $perPage)
        );
    }

    public function show(string $id)
    {
        $supplier = $this->supplierService->findOrFail((int) $id);

        return response()->json([
            'message' => 'تم جلب بيانات المورد بنجاح',
            'data'    => $supplier,
        ]);
    }

    public function store(StoreSupplierRequest $request)
    {
        $supplier = $this->supplierService->create($request->validated());

        return response()->json([
            'message' => 'تم إضافة المورد بنجاح',
            'data'    => $supplier,
        ], 201);
    }

    public function update(UpdateSupplierRequest $request, string $id)
    {
        $supplier = Supplier::findOrFail($id);
        $updated  = $this->supplierService->update($supplier, $request->validated());

        return response()->json([
            'message' => 'تم تحديث بيانات المورد بنجاح',
            'data'    => $updated,
        ]);
    }

    public function destroy(string $id)
    {
        $supplier = Supplier::findOrFail($id);
        $this->supplierService->delete($supplier);

        return response()->json([
            'message' => 'تم حذف المورد بنجاح',
        ]);
    }
}
