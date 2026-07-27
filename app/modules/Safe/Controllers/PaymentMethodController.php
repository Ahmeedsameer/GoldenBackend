<?php

namespace App\Modules\Safe\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Modules\Safe\Requests\StorePaymentMethodRequest;
use App\Modules\Safe\Requests\UpdatePaymentMethodRequest;

/**
 * Admin-managed Payment Methods (Cash EGP, Visa CIB, Vodafone Cash, InstaPay,
 * Fawry, ...) — the real, unlimited replacement for the old hardcoded
 * App\Modules\Sales\Enums\PaymentMethod. Read access for the cashier's own
 * dropdown lives elsewhere (CashierController::getPaymentMethods /
 * ManagerController equivalent) — this controller is admin CRUD only.
 */
class PaymentMethodController extends Controller
{
    public function index()
    {
        $methods = PaymentMethod::with(['currency:id,code,symbol', 'safe.shop:id,name', 'shops:id,name'])
            ->when(request('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return response()->json(['message' => 'تم جلب وسائل الدفع بنجاح', 'data' => $methods]);
    }

    public function store(StorePaymentMethodRequest $request)
    {
        $data = $this->normalizeFee($request->validated());
        $method = PaymentMethod::create($data);
        $method->shops()->sync($request->input('shop_ids', []));

        return response()->json([
            'message' => 'تم إضافة وسيلة الدفع بنجاح',
            'data' => $method->load(['currency:id,code,symbol', 'safe.shop:id,name', 'shops:id,name']),
        ], 201);
    }

    public function update(UpdatePaymentMethodRequest $request, string $id)
    {
        $method = PaymentMethod::findOrFail($id);
        $data = $this->normalizeFee(array_merge($method->only(['type']), $request->validated()));
        $method->update($data);

        // Branch restriction — omitting shop_ids entirely leaves the current
        // restriction untouched; sending an empty array explicitly clears it
        // back to "unrestricted" (available everywhere).
        if ($request->has('shop_ids')) {
            $method->shops()->sync($request->input('shop_ids', []));
        }

        return response()->json([
            'message' => 'تم تحديث وسيلة الدفع بنجاح',
            'data' => $method->fresh()->load(['currency:id,code,symbol', 'safe.shop:id,name', 'shops:id,name']),
        ]);
    }

    public function toggle(string $id)
    {
        $method = PaymentMethod::findOrFail($id);
        $method->update(['is_active' => ! $method->is_active]);

        return response()->json([
            'message' => $method->is_active ? 'تم تفعيل وسيلة الدفع' : 'تم إيقاف وسيلة الدفع',
            'data' => $method->fresh(),
        ]);
    }

    /** A processing fee only ever means something on a card type — never trust the client to have hidden the field correctly. */
    private function normalizeFee(array $data): array
    {
        $type = $data['type'] ?? null;
        if ($type && ! in_array($type, PaymentMethod::CARD_TYPES, true)) {
            $data['processing_fee_percent'] = 0;
        }

        return $data;
    }
}
