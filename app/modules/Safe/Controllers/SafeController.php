<?php

namespace App\Modules\Safe\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Safe;
use App\Modules\Safe\Requests\AdminSafeTransactionRequest;
use App\Modules\Safe\Requests\ManagerSafeTransactionRequest;
use App\Modules\Safe\Requests\SafeTransferRequest;
use App\Modules\Safe\Services\SafeService;

class SafeController extends Controller
{
    public function __construct(private SafeService $safeService) {}

    // ── Admin: any safe ───────────────────────────────────────────────────────

    /** GET /api/safe/shops/{shopId} */
    public function adminShopSafes(string $shopId)
    {
        $safes = $this->safeService->getShopSafes((int) $shopId);

        return response()->json([
            'message' => 'تم جلب خزن الفرع بنجاح',
            'data'    => $safes,
        ]);
    }

    /** GET /api/safe/{safeId} */
    public function adminShowSafe(string $safeId)
    {
        $safe = $this->safeService->getSafeById((int) $safeId);

        return response()->json([
            'message' => 'تم جلب بيانات الخزنة بنجاح',
            'data'    => $safe,
        ]);
    }

    /** GET /api/safe/{safeId}/transactions */
    public function adminTransactions(string $safeId)
    {
        $safe    = Safe::findOrFail($safeId);
        $filters = request()->only(['type', 'direction', 'currency_id', 'date_from', 'date_to', 'payment_method_id']);
        $perPage = request()->integer('per_page', 20);

        return response()->json([
            'message' => 'تم جلب سجل المعاملات بنجاح',
            'safe'    => ['id' => $safe->id, 'balances' => $safe->load('balances.currency')->balances],
            'data'    => $this->safeService->getTransactions($safe, $filters, $perPage),
        ]);
    }

    /** POST /api/safe/{safeId}/deposit */
    public function adminDeposit(AdminSafeTransactionRequest $request, string $safeId)
    {
        $safe = Safe::findOrFail($safeId);
        $v    = $request->validated();

        $tx = $this->safeService->adminDeposit(
            $safe, (int) $v['currency_id'], (float) $v['amount'],
            (int) $v['reason_id'], $v['note'] ?? null, auth()->id(),
            isset($v['payment_method_id']) ? (int) $v['payment_method_id'] : null
        );

        return response()->json([
            'message'     => 'تم الإيداع بنجاح',
            'transaction' => $tx->load('currency', 'reason', 'user:id,name', 'paymentMethod:id,name'),
            'new_balance' => SafeService::currentBalance($safe->id, (int) $v['currency_id']),
        ]);
    }

    /** POST /api/safe/{safeId}/withdraw */
    public function adminWithdraw(AdminSafeTransactionRequest $request, string $safeId)
    {
        $safe = Safe::findOrFail($safeId);
        $v    = $request->validated();

        $tx = $this->safeService->adminWithdraw(
            $safe, (int) $v['currency_id'], (float) $v['amount'],
            (int) $v['reason_id'], $v['note'] ?? null, auth()->id(),
            isset($v['payment_method_id']) ? (int) $v['payment_method_id'] : null
        );

        return response()->json([
            'message'     => 'تم السحب بنجاح',
            'transaction' => $tx->load('currency', 'reason', 'user:id,name', 'paymentMethod:id,name'),
            'new_balance' => SafeService::currentBalance($safe->id, (int) $v['currency_id']),
        ]);
    }

    /** POST /api/safe/transfer — also covers same-branch child-safe (payment method) transfers, see SafeTransferRequest. */
    public function transfer(SafeTransferRequest $request)
    {
        $v        = $request->validated();
        $from     = Safe::findOrFail($v['from_safe_id']);
        $to       = Safe::findOrFail($v['to_safe_id']);
        $transfer = $this->safeService->transfer(
            $from, $to,
            (int) $v['currency_id'],
            (float) $v['amount'],
            $v['note'] ?? null,
            auth()->id(),
            isset($v['from_payment_method_id']) ? (int) $v['from_payment_method_id'] : null,
            isset($v['to_payment_method_id']) ? (int) $v['to_payment_method_id'] : null
        );

        return response()->json([
            'message' => 'تم التحويل بنجاح',
            'data'    => $transfer,
        ]);
    }

    // ── Manager: own shop safes ───────────────────────────────────────────────

    /** GET /api/safe/my-shop */
    public function managerShopSafes()
    {
        return response()->json([
            'message' => 'تم جلب خزن الفرع بنجاح',
            'data'    => $this->safeService->getManagerSafes(),
        ]);
    }

    /** GET /api/safe/my-shop/{safeId}/transactions */
    public function managerTransactions(string $safeId)
    {
        $safe    = $this->safeService->getManagerSafeById((int) $safeId);
        $filters = request()->only(['type', 'direction', 'currency_id', 'date_from', 'date_to', 'payment_method_id']);
        $perPage = request()->integer('per_page', 20);

        return response()->json([
            'message' => 'تم جلب سجل المعاملات بنجاح',
            'safe'    => ['id' => $safe->id, 'balances' => $safe->balances],
            'data'    => $this->safeService->getTransactions($safe, $filters, $perPage),
        ]);
    }

    /** POST /api/safe/my-shop/{safeId}/deposit */
    public function managerDeposit(ManagerSafeTransactionRequest $request, string $safeId)
    {
        $safe = $this->safeService->getManagerSafeById((int) $safeId);
        $v    = $request->validated();

        $tx = $this->safeService->managerDeposit(
            $safe, (int) $v['currency_id'], (float) $v['amount'],
            (int) $v['reason_id'], $v['note'] ?? null, auth()->id(),
            isset($v['payment_method_id']) ? (int) $v['payment_method_id'] : null
        );

        return response()->json([
            'message'     => 'تم الإيداع بنجاح',
            'transaction' => $tx->load('currency', 'reason', 'user:id,name', 'paymentMethod:id,name'),
            'new_balance' => SafeService::currentBalance($safe->id, (int) $v['currency_id']),
        ]);
    }

    /** POST /api/safe/my-shop/{safeId}/withdraw */
    public function managerWithdraw(ManagerSafeTransactionRequest $request, string $safeId)
    {
        $safe = $this->safeService->getManagerSafeById((int) $safeId);
        $v    = $request->validated();

        $tx = $this->safeService->managerWithdraw(
            $safe, (int) $v['currency_id'], (float) $v['amount'],
            (int) $v['reason_id'], $v['note'] ?? null, auth()->id(),
            isset($v['payment_method_id']) ? (int) $v['payment_method_id'] : null
        );

        return response()->json([
            'message'     => 'تم السحب بنجاح',
            'transaction' => $tx->load('currency', 'reason', 'user:id,name', 'paymentMethod:id,name'),
            'new_balance' => SafeService::currentBalance($safe->id, (int) $v['currency_id']),
        ]);
    }
}
