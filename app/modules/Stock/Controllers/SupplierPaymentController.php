<?php

namespace App\Modules\Stock\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Safe;
use App\Models\Supply;
use App\Modules\Stock\Requests\StoreSupplierPaymentRequest;
use App\Modules\Stock\Services\SupplierPaymentService;

/**
 * Admin pays a supplier — always against exactly ONE invoice (Supply), from
 * whichever Safe the admin picks (Main Safe, a branch's, or any other active
 * safe). Reuses the existing Safe system end-to-end via SupplierPaymentService
 * -> SafeService; no parallel cash system.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(private SupplierPaymentService $service) {}

    public function index()
    {
        $filters = request()->only(['supplier_id', 'supply_id', 'date_from', 'date_to']);
        $perPage = request()->integer('per_page', 25);

        return response()->json(['message' => 'ok', 'data' => $this->service->list($filters, $perPage)]);
    }

    public function store(StoreSupplierPaymentRequest $request)
    {
        $data = $request->validated();
        $invoice = Supply::findOrFail($data['supply_id']);
        $safe = Safe::findOrFail($data['safe_id']);

        $payment = $this->service->pay($invoice, $safe, (int) $data['currency_id'], (float) $data['amount'], $request->user(), $data['note'] ?? null);

        return response()->json(['message' => 'تم تسجيل دفعة المورد بنجاح', 'data' => $payment], 201);
    }
}
