<?php

namespace App\Modules\Convention\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Convention;
use App\Models\ConventionTransaction;
use App\Modules\Convention\Requests\StoreConventionTransactionRequest;
use App\Modules\Convention\Requests\UpdateConventionTransactionRequest;
use App\Modules\Convention\Services\ConventionService;

class ConventionTransactionController extends Controller
{
    public function __construct(private ConventionService $conventionService) {}

    /** GET /api/conventions/{id}/transactions */
    public function index(string $id)
    {
        $convention = $this->conventionService->findOrFail((int) $id);
        $filters    = request()->only(['type', 'manager_id', 'search', 'date_from', 'date_to', 'sort_by', 'sort_dir']);
        $perPage    = request()->integer('per_page', 20);

        return response()->json([
            'message'    => 'تم جلب حركات العهدة بنجاح',
            'convention' => $convention,
            'data'       => $this->conventionService->getTransactions($convention, $filters, $perPage),
        ]);
    }

    /** POST /api/conventions/{id}/transactions  (admin-recorded withdrawal) */
    public function store(StoreConventionTransactionRequest $request, string $id)
    {
        $convention = Convention::findOrFail($id);

        $transaction = $this->conventionService->withdraw(
            $convention,
            $request->validated(),
            $request->integer('manager_id') ?: null,
            auth()->id()
        );

        return response()->json([
            'message'     => 'تم تسجيل الحركة بنجاح',
            'transaction' => $transaction,
            'remaining'   => $this->conventionService->remaining($convention->fresh()),
        ], 201);
    }

    /** PUT /api/conventions/transactions/{txId} */
    public function update(UpdateConventionTransactionRequest $request, string $txId)
    {
        $transaction = ConventionTransaction::findOrFail($txId);
        $updated     = $this->conventionService->updateTransaction($transaction, $request->validated());

        return response()->json([
            'message'     => 'تم تحديث الحركة بنجاح',
            'transaction' => $updated,
            'remaining'   => $this->conventionService->remaining($transaction->convention->fresh()),
        ]);
    }

    /** DELETE /api/conventions/transactions/{txId}  (soft delete) */
    public function destroy(string $txId)
    {
        $transaction = ConventionTransaction::findOrFail($txId);
        $this->conventionService->deleteTransaction($transaction);

        return response()->json([
            'message' => 'تم حذف الحركة بنجاح',
        ]);
    }
}
