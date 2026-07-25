<?php

namespace App\Modules\Safe\Services;

use App\Models\Invoice;
use App\Models\Safe;
use App\Models\SafeBalance;
use App\Models\SalaryAdvance;
use App\Models\SafeTransaction;
use App\Models\SafeTransfer;
use App\Models\SafeType;
use App\Models\Shop;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;

class SafeService
{
    // ── Safe creation (admin) ─────────────────────────────────────────────────

    public function createSafe(array $data): Safe
    {
        $safeType = SafeType::findOrFail($data['safe_type_id']);
        $shopId   = $data['shop_id'] ?? null;

        if ($safeType->isPhysical()) {
            $exists = Safe::where('shop_id', $shopId)
                ->whereHas('safeType', fn($q) => $q->where('kind', 'physical'))
                ->exists();

            $location = $shopId ? 'هذا الفرع' : 'الشركة';
            if ($exists) {
                abort(422, "يوجد خزنة نقدية بالفعل في {$location}");
            }
        }

        return Safe::create([
            'shop_id'      => $shopId,
            'safe_type_id' => $data['safe_type_id'],
            'is_active'    => true,
        ]);
    }

    // ── Safe lookup helpers ───────────────────────────────────────────────────

    public function getSafeById(int $safeId): Safe
    {
        return Safe::with(['safeType', 'shop:id,name', 'balances.currency'])
            ->findOrFail($safeId);
    }

    public function getShopSafes(int $shopId): \Illuminate\Database\Eloquent\Collection
    {
        return Safe::with(['safeType', 'balances.currency'])
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->get();
    }

    public function getManagerSafes(): \Illuminate\Database\Eloquent\Collection
    {
        $shop = Shop::where('manager_id', auth()->id())->firstOrFail();
        return $this->getShopSafes($shop->id);
    }

    public function getManagerSafeById(int $safeId): Safe
    {
        $shop = Shop::where('manager_id', auth()->id())->firstOrFail();

        $safe = Safe::with(['safeType', 'balances.currency'])
            ->where('shop_id', $shop->id)
            ->where('is_active', true)
            ->findOrFail($safeId);

        return $safe;
    }

    // ── Transaction history ───────────────────────────────────────────────────

    public function getTransactions(Safe $safe, array $filters, int $perPage): mixed
    {
        $query = $safe->transactions()
            ->with('user:id,name,role', 'currency', 'reason', 'invoice:id,status,date')
            ->latest();

        if (! empty($filters['type']))       $query->where('type', $filters['type']);
        if (! empty($filters['direction']))  $query->where('direction', $filters['direction']);
        if (! empty($filters['currency_id'])) $query->where('currency_id', $filters['currency_id']);
        if (! empty($filters['date_from']))  $query->whereDate('created_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to']))    $query->whereDate('created_at', '<=', $filters['date_to']);

        return $query->paginate($perPage);
    }

    // ── Admin manual transactions ─────────────────────────────────────────────

    public function adminDeposit(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId): SafeTransaction
    {
        $this->validateReason($reasonId, 'in');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId) {
            return $this->applyTransaction($safe, 'admin_deposit', $currencyId, $amount, $userId, $reasonId, $note);
        });
    }

    public function adminWithdraw(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId): SafeTransaction
    {
        $this->validateReason($reasonId, 'out');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId) {
            $this->guardAgainstOverdraft($safe->id, $currencyId, $amount);
            return $this->applyTransaction($safe, 'admin_withdrawal', $currencyId, $amount, $userId, $reasonId, $note);
        });
    }

    // ── Manager manual transactions ───────────────────────────────────────────

    public function managerDeposit(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId): SafeTransaction
    {
        $this->validateReason($reasonId, 'in');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId) {
            return $this->applyTransaction($safe, 'manager_deposit', $currencyId, $amount, $userId, $reasonId, $note);
        });
    }

    public function managerWithdraw(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId): SafeTransaction
    {
        $this->validateReason($reasonId, 'out');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId) {
            $this->guardAgainstOverdraft($safe->id, $currencyId, $amount);
            return $this->applyTransaction($safe, 'manager_expense', $currencyId, $amount, $userId, $reasonId, $note);
        });
    }

    // ── Admin transfer between safes ──────────────────────────────────────────

    public function transfer(Safe $from, Safe $to, int $currencyId, float $amount, ?string $note, int $adminId): SafeTransfer
    {
        return DB::transaction(function () use ($from, $to, $currencyId, $amount, $note, $adminId) {
            $this->guardAgainstOverdraft($from->id, $currencyId, $amount);

            $transfer = SafeTransfer::create([
                'from_safe_id' => $from->id,
                'to_safe_id'   => $to->id,
                'currency_id'  => $currencyId,
                'amount'       => $amount,
                'note'         => $note,
                'admin_id'     => $adminId,
            ]);

            $this->applyTransaction($from, 'transfer_out', $currencyId, $amount, $adminId, null, $note, null, $transfer->id);
            $this->applyTransaction($to,   'transfer_in',  $currencyId, $amount, $adminId, null, $note, null, $transfer->id);

            return $transfer->load('fromSafe.safeType', 'toSafe.safeType', 'currency', 'admin:id,name');
        });
    }

    // ── Internal: called by SalesService inside its existing DB::transaction ──

    public function recordSaleTransaction(
        Safe    $safe,
        Invoice $invoice,
        int     $currencyId,
        float   $amount,
        int     $userId
    ): SafeTransaction {
        return $this->applyTransaction($safe, 'sale', $currencyId, $amount, $userId, null, null, $invoice->id);
    }

    // ── Salary Advance disbursement / repayment (Phase 6.1/6.2) ───────────────
    // The admin picks WHICH Safe/Custody pays an advance out (any safe — Main
    // Safe or any branch's), and WHICH Safe/Custody receives each repayment.
    // These are ordinary outgoing/incoming SafeTransaction rows, exactly like
    // every other manual movement — just linked back to the SalaryAdvance via
    // its own dedicated FK instead of invoice_id/transfer_id.

    public function recordAdvanceDisbursement(
        Safe          $safe,
        SalaryAdvance $advance,
        int           $currencyId,
        float         $amount,
        int           $userId,
        ?string       $note = null
    ): SafeTransaction {
        return DB::transaction(function () use ($safe, $advance, $currencyId, $amount, $userId, $note) {
            $this->guardAgainstOverdraft($safe->id, $currencyId, $amount);
            return $this->applyTransaction($safe, 'advance_disbursement', $currencyId, $amount, $userId, null, $note, null, null, $advance->id);
        });
    }

    public function recordAdvanceRepayment(
        Safe          $safe,
        SalaryAdvance $advance,
        int           $currencyId,
        float         $amount,
        int           $userId,
        ?string       $note = null
    ): SafeTransaction {
        return DB::transaction(function () use ($safe, $advance, $currencyId, $amount, $userId, $note) {
            return $this->applyTransaction($safe, 'advance_repayment', $currencyId, $amount, $userId, null, $note, null, null, $advance->id);
        });
    }

    // ── Supplier payment (Supplier Management) ────────────────────────────────
    // The admin picks WHICH Safe pays a supplier (Main Safe, a branch's, or
    // any other active safe) — same pattern as Salary Advance disbursement.
    // Called from inside SupplierPaymentService::pay()'s own DB::transaction.

    public function recordSupplierPayment(
        Safe            $safe,
        SupplierPayment $payment,
        int             $currencyId,
        float           $amount,
        int             $userId,
        ?string         $note = null
    ): SafeTransaction {
        $this->guardAgainstOverdraft($safe->id, $currencyId, $amount);
        return $this->applyTransaction($safe, 'supplier_payment', $currencyId, $amount, $userId, null, $note, null, null, null, $payment->id);
    }

    // ── Private: core write ───────────────────────────────────────────────────

    private function applyTransaction(
        Safe    $safe,
        string  $type,
        int     $currencyId,
        float   $amount,
        int     $userId,
        ?int    $reasonId          = null,
        ?string $note              = null,
        ?int    $invoiceId         = null,
        ?int    $transferId        = null,
        ?int    $salaryAdvanceId   = null,
        ?int    $supplierPaymentId = null
    ): SafeTransaction {
        $direction = SafeTransaction::DIRECTION_MAP[$type];

        $this->updateBalance($safe->id, $currencyId, $direction, $amount);

        return SafeTransaction::create([
            'safe_id'              => $safe->id,
            'type'                 => $type,
            'direction'            => $direction,
            'currency_id'          => $currencyId,
            'amount'               => $amount,
            'reason_id'            => $reasonId,
            'note'                 => $note,
            'invoice_id'           => $invoiceId,
            'transfer_id'          => $transferId,
            'salary_advance_id'    => $salaryAdvanceId,
            'supplier_payment_id'  => $supplierPaymentId,
            'user_id'              => $userId,
        ]);
    }

    private function updateBalance(int $safeId, int $currencyId, string $direction, float $amount): void
    {
        $balance = SafeBalance::where('safe_id', $safeId)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        if ($balance) {
            $direction === 'in'
                ? $balance->increment('balance', $amount)
                : $balance->decrement('balance', $amount);
        } else {
            SafeBalance::create([
                'safe_id'     => $safeId,
                'currency_id' => $currencyId,
                'balance'     => $direction === 'in' ? $amount : -$amount,
            ]);
        }
    }

    private function guardAgainstOverdraft(int $safeId, int $currencyId, float $amount): void
    {
        $balance = SafeBalance::where('safe_id', $safeId)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        $current = $balance ? (float) $balance->balance : 0;

        if ($current < $amount) {
            abort(422, "الرصيد غير كافٍ. الرصيد الحالي: {$current}");
        }
    }

    // ── Public static helper ──────────────────────────────────────────────────

    public static function currentBalance(int $safeId, int $currencyId): float
    {
        return (float) (SafeBalance::where('safe_id', $safeId)
            ->where('currency_id', $currencyId)
            ->value('balance') ?? 0);
    }

    private function validateReason(int $reasonId, string $direction): void
    {
        $reason = \App\Models\TransactionReason::where('id', $reasonId)
            ->where('is_active', true)
            ->firstOrFail();

        if (! $reason->isValidFor($direction)) {
            $label = $direction === 'in' ? 'إيداع' : 'سحب';
            abort(422, "السبب المحدد غير صالح لعمليات الـ{$label}");
        }
    }
}
