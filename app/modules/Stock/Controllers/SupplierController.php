<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Modules\Stock\Requests\StoreSupplierRequest;
use App\Modules\Stock\Requests\UpdateSupplierRequest;
use App\Modules\Stock\Services\SupplierService;

class SupplierController extends Controller
{
    public function __construct(private SupplierService $supplierService) {}

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
