<?php

namespace App\Modules\Safe\Controllers;

use App\Http\Controllers\Controller;
use App\Models\SafeType;
use App\Modules\Safe\Requests\StoreSafeTypeRequest;
use App\Modules\Safe\Requests\UpdateSafeTypeRequest;

class SafeTypeController extends Controller
{
    public function index()
    {
        $types = SafeType::query()
            ->when(request('kind'), fn($q) => $q->where('kind', request('kind')))
            ->get();

        return response()->json([
            'message' => 'تم جلب أنواع الخزن بنجاح',
            'data'    => $types,
        ]);
    }

    public function store(StoreSafeTypeRequest $request)
    {
        $type = SafeType::create($request->validated());

        return response()->json([
            'message' => 'تم إضافة نوع الخزنة بنجاح',
            'data'    => $type,
        ], 201);
    }

    public function update(UpdateSafeTypeRequest $request, string $id)
    {
        $type = SafeType::findOrFail($id);

        // Protect the seeded physical type from being deactivated if it has active safes
        if ($type->kind === 'physical' && isset($request->validated()['is_active']) && ! $request->validated()['is_active']) {
            $hasActiveSafes = $type->safes()->where('is_active', true)->exists();
            if ($hasActiveSafes) {
                return response()->json(['message' => 'لا يمكن تعطيل هذا النوع لأنه مرتبط بخزن نشطة'], 422);
            }
        }

        $type->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث نوع الخزنة بنجاح',
            'data'    => $type->fresh(),
        ]);
    }
}
