<?php

namespace App\Modules\Convention\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Convention\Requests\ManagerWithdrawRequest;
use App\Modules\Convention\Services\ConventionService;

class ManagerConventionController extends Controller
{
    public function __construct(private ConventionService $conventionService) {}

    /** GET /api/manager/conventions — the manager's own branch conventions */
    public function index()
    {
        return response()->json([
            'message' => 'تم جلب العهد الخاصة بك بنجاح',
            'data'    => $this->conventionService->getForManager(auth()->id()),
        ]);
    }

    /** GET /api/manager/conventions/{id} */
    public function show(string $id)
    {
        $convention = $this->conventionService->findForManagerOrFail((int) $id, auth()->id());

        return response()->json([
            'message' => 'تم جلب بيانات العهدة بنجاح',
            'data'    => $convention,
        ]);
    }

    /** GET /api/manager/conventions/{id}/transactions */
    public function transactions(string $id)
    {
        $convention = $this->conventionService->findForManagerOrFail((int) $id, auth()->id());
        $filters    = request()->only(['type', 'search', 'date_from', 'date_to', 'sort_by', 'sort_dir']);
        $perPage    = request()->integer('per_page', 20);

        return response()->json([
            'message'    => 'تم جلب حركات العهدة بنجاح',
            'convention' => $convention,
            'data'       => $this->conventionService->getTransactions($convention, $filters, $perPage),
        ]);
    }

    /** POST /api/manager/conventions/{id}/withdraw */
    public function withdraw(ManagerWithdrawRequest $request, string $id)
    {
        $convention = $this->conventionService->findForManagerOrFail((int) $id, auth()->id());

        $transaction = $this->conventionService->withdraw(
            $convention,
            $request->validated(),
            auth()->id(),   // manager_id = the authenticated manager
            null
        );

        return response()->json([
            'message'     => 'تم السحب من العهدة بنجاح',
            'transaction' => $transaction,
            'remaining'   => $this->conventionService->remaining($convention->fresh()),
        ], 201);
    }
}
