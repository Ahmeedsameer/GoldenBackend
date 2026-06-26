<?php

namespace App\Modules\Safe\Controllers;

use App\Http\Controllers\Controller;
use App\Models\TransactionReason;
use App\Modules\Safe\Requests\StoreTransactionReasonRequest;
use App\Modules\Safe\Requests\UpdateTransactionReasonRequest;

class TransactionReasonController extends Controller
{
    public function index()
    {
        $reasons = TransactionReason::query()
            ->when(request('direction'), fn($q) => $q->whereIn('direction', [request('direction'), 'both']))
            ->when(request('active_only'), fn($q) => $q->where('is_active', true))
            ->get();

        return response()->json([
            'message' => 'تم جلب قائمة أسباب المعاملات بنجاح',
            'data'    => $reasons,
        ]);
    }

    public function store(StoreTransactionReasonRequest $request)
    {
        $reason = TransactionReason::create($request->validated());

        return response()->json([
            'message' => 'تم إضافة السبب بنجاح',
            'data'    => $reason,
        ], 201);
    }

    public function update(UpdateTransactionReasonRequest $request, string $id)
    {
        $reason = TransactionReason::findOrFail($id);
        $reason->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث السبب بنجاح',
            'data'    => $reason->fresh(),
        ]);
    }
}
