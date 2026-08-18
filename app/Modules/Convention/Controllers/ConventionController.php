<?php

namespace App\Modules\Convention\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Convention;
use App\Modules\Convention\Requests\StoreConventionRequest;
use App\Modules\Convention\Requests\UpdateConventionRequest;
use App\Modules\Convention\Services\ConventionService;

class ConventionController extends Controller
{
    public function __construct(private ConventionService $conventionService) {}

    /** GET /api/conventions */
    public function index()
    {
        $filters = request()->only(['shop_id', 'search']);
        $perPage = request()->integer('per_page', 15);

        return response()->json([
            'message' => 'تم جلب قائمة العهد بنجاح',
            'data'    => $this->conventionService->getAll($filters, $perPage),
        ]);
    }

    /** GET /api/conventions/shop/{shopId} */
    public function byShop(string $shopId)
    {
        return response()->json([
            'message' => 'تم جلب عهد الفرع بنجاح',
            'data'    => $this->conventionService->getByShop((int) $shopId),
        ]);
    }

    /** GET /api/conventions/show/{id} */
    public function show(string $id)
    {
        return response()->json([
            'message' => 'تم جلب بيانات العهدة بنجاح',
            'data'    => $this->conventionService->findOrFail((int) $id),
        ]);
    }

    /** POST /api/conventions/create */
    public function store(StoreConventionRequest $request)
    {
        $convention = $this->conventionService->create($request->validated());

        return response()->json([
            'message' => 'تم إنشاء العهدة بنجاح',
            'data'    => $convention,
        ], 201);
    }

    /** PUT /api/conventions/update/{id} */
    public function update(UpdateConventionRequest $request, string $id)
    {
        $convention = Convention::findOrFail($id);
        $updated    = $this->conventionService->update($convention, $request->validated());

        return response()->json([
            'message' => 'تم تحديث العهدة بنجاح',
            'data'    => $updated,
        ]);
    }

    /** DELETE /api/conventions/destroy/{id} */
    public function destroy(string $id)
    {
        $convention = Convention::findOrFail($id);
        $this->conventionService->delete($convention);

        return response()->json([
            'message' => 'تم حذف العهدة بنجاح',
        ]);
    }
}
