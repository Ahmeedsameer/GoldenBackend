<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use App\Modules\Stock\Requests\StoreSupplyRequest;
use App\Modules\Stock\Requests\UpdateSupplyRequest;
use App\Modules\Stock\Services\SupplyService;

class SupplyController extends Controller
{
    public function __construct(private SupplyService $supplyService) {}

    public function index()
    {
        $filters = request()->only([
            'supplier_id',
            'payment_method',
            'date_from',
            'date_to',
        ]);
        $perPage = request()->integer('per_page', 15);

        return response()->json(
            $this->supplyService->getAll($filters, $perPage)
        );
    }

    public function show(string $id)
    {
        $supply = $this->supplyService->findOrFail((int) $id);

        return response()->json([
            'message' => 'تم جلب بيانات التوريد بنجاح',
            'data'    => $supply,
        ]);
    }

    /** GET /api/stock/supplier-intelligence?product_type=RAW_MATERIAL */
    public function supplierIntelligence()
    {
        $productType = request()->validate([
            'product_type' => 'required|string|in:RAW_MATERIAL,PACKAGING,READY_PRODUCT',
        ])['product_type'];

        return response()->json([
            'message' => 'ok',
            'data' => $this->supplyService->supplierIntelligence($productType),
        ]);
    }

    public function store(StoreSupplyRequest $request)
    {
        $supply = $this->supplyService->create($request->validated(), $request->user());

        return response()->json([
            'message' => 'تم تسجيل التوريد وإضافة الأصناف إلى المستودع الرئيسي بنجاح',
            'data'    => $supply,
        ], 201);
    }

    public function update(UpdateSupplyRequest $request, string $id)
    {
        $supply  = Supply::findOrFail($id);
        $updated = $this->supplyService->update($supply, $request->validated());

        return response()->json([
            'message' => 'تم تحديث بيانات التوريد بنجاح',
            'data'    => $updated,
        ]);
    }

    public function destroy(string $id)
    {
        $supply = Supply::findOrFail($id);
        $this->supplyService->delete($supply);

        return response()->json([
            'message' => 'تم حذف التوريد وجميع أصنافه من المستودع بنجاح',
        ]);
    }
}
