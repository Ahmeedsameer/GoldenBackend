<?php

namespace App\Modules\Safe\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Safe;
use App\Modules\Safe\Requests\StoreSafeRequest;
use App\Modules\Safe\Services\SafeService;

class SafeManagementController extends Controller
{
    public function __construct(private SafeService $safeService) {}

    /**
     * GET /api/safe-management?shop_id=3
     * Null shop_id → company safes
     */
    public function index()
    {
        $query = Safe::with(['safeType', 'shop:id,name', 'balances.currency'])
            ->when(request()->has('shop_id'), function ($q) {
                $shopId = request('shop_id');
                $shopId === null || $shopId === 'null'
                    ? $q->whereNull('shop_id')
                    : $q->where('shop_id', $shopId);
            });

        return response()->json([
            'message' => 'تم جلب قائمة الخزن بنجاح',
            'data'    => $query->get(),
        ]);
    }

    /**
     * POST /api/safe-management
     */
    public function store(StoreSafeRequest $request)
    {
        $safe = $this->safeService->createSafe($request->validated());

        return response()->json([
            'message' => 'تم إنشاء الخزنة بنجاح',
            'data'    => $safe->load('safeType', 'shop:id,name'),
        ], 201);
    }

    /**
     * PUT /api/safe-management/{id}/toggle
     */
    public function toggle(string $id)
    {
        $safe = Safe::findOrFail($id);

        // Cannot deactivate a shop's only physical safe
        if ($safe->isPhysical() && $safe->is_active) {
            $physicalCount = Safe::where('shop_id', $safe->shop_id)
                ->whereHas('safeType', fn($q) => $q->where('kind', 'physical'))
                ->where('is_active', true)
                ->count();

            if ($physicalCount <= 1) {
                return response()->json(['message' => 'لا يمكن تعطيل الخزنة النقدية الوحيدة للفرع'], 422);
            }
        }

        $safe->update(['is_active' => ! $safe->is_active]);

        return response()->json([
            'message' => $safe->is_active ? 'تم تفعيل الخزنة' : 'تم تعطيل الخزنة',
            'data'    => $safe->fresh('safeType'),
        ]);
    }
}
