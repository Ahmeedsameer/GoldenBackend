<?php

namespace App\Modules\Safe\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Modules\Safe\Requests\StoreCurrencyRequest;
use App\Modules\Safe\Requests\UpdateCurrencyRequest;

class CurrencyController extends Controller
{
    public function index()
    {
        $currencies = Currency::query()
            ->when(request('active_only'), fn($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        return response()->json([
            'message' => 'تم جلب قائمة العملات بنجاح',
            'data'    => $currencies,
        ]);
    }

    public function store(StoreCurrencyRequest $request)
    {
        $currency = Currency::create($request->validated());

        return response()->json([
            'message' => 'تم إضافة العملة بنجاح',
            'data'    => $currency,
        ], 201);
    }

    public function update(UpdateCurrencyRequest $request, string $id)
    {
        $currency = Currency::findOrFail($id);
        $currency->update($request->validated());

        return response()->json([
            'message' => 'تم تحديث بيانات العملة بنجاح',
            'data'    => $currency->fresh(),
        ]);
    }
}
