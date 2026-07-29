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
        $safe = Safe::with(['safeType', 'shop:id,name', 'balances.currency'])
            ->findOrFail($safeId);
        $this->attachBalancesByMethod([$safe]);

        return $safe;
    }

    public function getShopSafes(int $shopId): \Illuminate\Database\Eloquent\Collection
    {
        $safes = Safe::with(['safeType', 'balances.currency'])
            ->where('shop_id', $shopId)
            ->where('is_active', true)
            ->get();
        $this->attachBalancesByMethod($safes);

        return $safes;
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
        $this->attachBalancesByMethod([$safe]);

        return $safe;
    }

    /**
     * Derives each currency's balance, split by payment method, straight from
     * SafeTransaction — never a stored/duplicated total. Because it sums the
     * exact same (direction, amount) pairs updateBalance() already used to
     * build SafeBalance.balance, SUM(methods[].balance) per currency always
     * equals SafeBalance.balance exactly — no drift possible.
     *
     * Transactions with no payment method (manual deposits/withdrawals,
     * transfers, advances, supplier payments — invoice_payment_id is null)
     * are bucketed under "أخرى (يدوي)" so totals always reconcile.
     *
     * @return array<int, array<int, array{currency: mixed, total: float, methods: array}>>
     */
    public function getBalancesByPaymentMethod(array $safeIds): array
    {
        if (empty($safeIds)) {
            return [];
        }

        $rows = SafeTransaction::query()
            ->whereIn('safe_transactions.safe_id', $safeIds)
            ->leftJoin('invoice_payments', 'safe_transactions.invoice_payment_id', '=', 'invoice_payments.id')
            ->leftJoin('payment_methods', DB::raw('COALESCE(safe_transactions.payment_method_id, invoice_payments.payment_method_id)'), '=', 'payment_methods.id')
            ->selectRaw('
                safe_transactions.safe_id                                  as safe_id,
                safe_transactions.currency_id                              as currency_id,
                COALESCE(payment_methods.id, 0)                            as payment_method_id,
                COALESCE(payment_methods.name, "أخرى (يدوي)")               as method_name,
                SUM(CASE WHEN safe_transactions.direction = "in" THEN safe_transactions.amount ELSE -safe_transactions.amount END) as balance
            ')
            ->groupBy('safe_transactions.safe_id', 'safe_transactions.currency_id', 'payment_methods.id', 'payment_methods.name')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->safe_id][$row->currency_id]['total']   = ($result[$row->safe_id][$row->currency_id]['total'] ?? 0) + (float) $row->balance;
            $result[$row->safe_id][$row->currency_id]['methods'][] = [
                'payment_method_id' => (int) $row->payment_method_id ?: null,
                'name'              => $row->method_name,
                'balance'           => round((float) $row->balance, 2),
            ];
        }

        foreach ($result as $safeId => &$byCurrency) {
            foreach ($byCurrency as &$entry) {
                $entry['total'] = round($entry['total'], 2);
            }
        }

        return $result;
    }

    /** Attaches `balances_by_method` (keyed by currency_id) onto each Safe — see getBalancesByPaymentMethod(). */
    private function attachBalancesByMethod(iterable $safes): void
    {
        $safes = is_array($safes) ? $safes : $safes->all();
        if (empty($safes)) {
            return;
        }

        $byMethod = $this->getBalancesByPaymentMethod(array_map(fn (Safe $s) => $s->id, $safes));

        foreach ($safes as $safe) {
            $safe->setAttribute('balances_by_method', $byMethod[$safe->id] ?? []);
        }
    }

    // ── Transaction history ───────────────────────────────────────────────────

    public function getTransactions(Safe $safe, array $filters, int $perPage): mixed
    {
        $query = $safe->transactions()
            ->with('user:id,name,role', 'currency', 'reason', 'invoice:id,status,date', 'paymentMethod:id,name,type', 'invoicePayment.paymentMethod:id,name,type')
            ->latest();

        if (! empty($filters['type']))       $query->where('type', $filters['type']);
        if (! empty($filters['direction']))  $query->where('direction', $filters['direction']);
        if (! empty($filters['currency_id'])) $query->where('currency_id', $filters['currency_id']);
        if (! empty($filters['date_from']))  $query->whereDate('created_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to']))    $query->whereDate('created_at', '<=', $filters['date_to']);
        if (! empty($filters['payment_method_id'])) {
            $methodId = (int) $filters['payment_method_id'];
            $query->where(function ($q) use ($methodId) {
                $q->where('payment_method_id', $methodId)
                  ->orWhereHas('invoicePayment', fn ($iq) => $iq->where('payment_method_id', $methodId));
            });
        }

        return $query->paginate($perPage);
    }

    // ── Admin manual transactions ─────────────────────────────────────────────

    public function adminDeposit(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId, ?int $paymentMethodId = null): SafeTransaction
    {
        $this->validateReason($reasonId, 'in');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId, $paymentMethodId) {
            return $this->applyTransaction($safe, 'admin_deposit', $currencyId, $amount, $userId, $reasonId, $note, null, null, null, null, null, $paymentMethodId);
        });
    }

    public function adminWithdraw(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId, ?int $paymentMethodId = null): SafeTransaction
    {
        $this->validateReason($reasonId, 'out');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId, $paymentMethodId) {
            $this->guardAgainstOverdraft($safe->id, $currencyId, $amount, $paymentMethodId);
            return $this->applyTransaction($safe, 'admin_withdrawal', $currencyId, $amount, $userId, $reasonId, $note, null, null, null, null, null, $paymentMethodId);
        });
    }

    // ── Manager manual transactions ───────────────────────────────────────────

    public function managerDeposit(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId, ?int $paymentMethodId = null): SafeTransaction
    {
        $this->validateReason($reasonId, 'in');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId, $paymentMethodId) {
            return $this->applyTransaction($safe, 'manager_deposit', $currencyId, $amount, $userId, $reasonId, $note, null, null, null, null, null, $paymentMethodId);
        });
    }

    /** manager_expense is the only "expense" concept in this codebase — a manager
     *  withdrawal, optionally scoped to one child safe (payment method). */
    public function managerWithdraw(Safe $safe, int $currencyId, float $amount, int $reasonId, ?string $note, int $userId, ?int $paymentMethodId = null): SafeTransaction
    {
        $this->validateReason($reasonId, 'out');

        return DB::transaction(function () use ($safe, $currencyId, $amount, $reasonId, $note, $userId, $paymentMethodId) {
            $this->guardAgainstOverdraft($safe->id, $currencyId, $amount, $paymentMethodId);
            return $this->applyTransaction($safe, 'manager_expense', $currencyId, $amount, $userId, $reasonId, $note, null, null, null, null, null, $paymentMethodId);
        });
    }

    // ── Admin transfer between safes (Sub Safes: also covers same-branch ──────
    // child-safe-to-child-safe transfers — from_safe_id === to_safe_id is only
    // valid when both payment method ids are given and differ; see
    // SafeTransferRequest. Reuses transfer_in/transfer_out exactly — no new
    // transaction type, so this stays outside AdminFinancialReportController's
    // REAL_TYPES automatically, exactly like every other transfer today.

    public function transfer(
        Safe    $from,
        Safe    $to,
        int     $currencyId,
        float   $amount,
        ?string $note,
        int     $adminId,
        ?int    $fromPaymentMethodId = null,
        ?int    $toPaymentMethodId   = null
    ): SafeTransfer {
        return DB::transaction(function () use ($from, $to, $currencyId, $amount, $note, $adminId, $fromPaymentMethodId, $toPaymentMethodId) {
            $this->guardAgainstOverdraft($from->id, $currencyId, $amount, $fromPaymentMethodId);

            $transfer = SafeTransfer::create([
                'from_safe_id'            => $from->id,
                'to_safe_id'              => $to->id,
                'currency_id'             => $currencyId,
                'from_payment_method_id'  => $fromPaymentMethodId,
                'to_payment_method_id'    => $toPaymentMethodId,
                'amount'                  => $amount,
                'note'                    => $note,
                'admin_id'                => $adminId,
            ]);

            $this->applyTransaction($from, 'transfer_out', $currencyId, $amount, $adminId, null, $note, null, $transfer->id, null, null, null, $fromPaymentMethodId);
            $this->applyTransaction($to,   'transfer_in',  $currencyId, $amount, $adminId, null, $note, null, $transfer->id, null, null, null, $toPaymentMethodId);

            return $transfer->load('fromSafe.safeType', 'toSafe.safeType', 'currency', 'admin:id,name', 'fromPaymentMethod:id,name', 'toPaymentMethod:id,name');
        });
    }

    // ── Internal: called by SalesService inside its existing DB::transaction ──

    public function recordSaleTransaction(
        Safe    $safe,
        Invoice $invoice,
        int     $currencyId,
        float   $amount,
        int     $userId,
        ?int    $invoicePaymentId = null,
        ?int    $paymentMethodId  = null
    ): SafeTransaction {
        return $this->applyTransaction($safe, 'sale', $currencyId, $amount, $userId, null, null, $invoice->id, null, null, null, $invoicePaymentId, $paymentMethodId);
    }

    /**
     * Reverses a sale when its invoice is cancelled — money leaves the same
     * safe/currency it originally arrived in (direction 'out', the existing
     * 'refund' type — already the exact bucket AdminFinancialReportController
     * expects for this). Guarded against overdraft like every other outflow:
     * if that safe's balance was already spent down since the sale, the
     * cancellation surfaces that instead of silently going negative.
     */
    public function recordSaleRefund(
        Safe    $safe,
        Invoice $invoice,
        int     $currencyId,
        float   $amount,
        int     $userId,
        ?string $note = null,
        ?int    $invoicePaymentId = null,
        ?int    $paymentMethodId  = null
    ): SafeTransaction {
        // Refund reverses only what THIS payment method actually contributed —
        // never gated against the whole safe, since another method's money must stay untouched.
        $this->guardAgainstOverdraft($safe->id, $currencyId, $amount, $paymentMethodId);
        return $this->applyTransaction($safe, 'refund', $currencyId, $amount, $userId, null, $note, $invoice->id, null, null, null, $invoicePaymentId, $paymentMethodId);
    }

    // ── Card/bank processing fee (Payment Methods Phase 2) ────────────────────
    // The fee must participate in accounting as a real, visible expense — not
    // just a smaller credit. Called right after recordSaleTransaction() credits
    // the FULL gross amount to the same safe; debiting the fee immediately
    // after nets the safe to exactly the same balance a direct net-credit
    // would have produced, but now as two transparent ledger rows instead of one.

    public function recordBankCharge(
        Safe    $safe,
        Invoice $invoice,
        int     $currencyId,
        float   $amount,
        int     $userId,
        ?int    $invoicePaymentId = null,
        ?string $note = null,
        ?int    $paymentMethodId  = null
    ): SafeTransaction {
        $this->guardAgainstOverdraft($safe->id, $currencyId, $amount, $paymentMethodId);
        return $this->applyTransaction($safe, 'bank_charge', $currencyId, $amount, $userId, null, $note, $invoice->id, null, null, null, $invoicePaymentId, $paymentMethodId);
    }

    /** Reverses a bank_charge on cancellation — mirrors recordSaleRefund exactly, same safe/currency, direction 'in'. */
    public function recordBankChargeReversal(
        Safe    $safe,
        Invoice $invoice,
        int     $currencyId,
        float   $amount,
        int     $userId,
        ?int    $invoicePaymentId = null,
        ?string $note = null,
        ?int    $paymentMethodId  = null
    ): SafeTransaction {
        return $this->applyTransaction($safe, 'bank_charge_reversal', $currencyId, $amount, $userId, null, $note, $invoice->id, null, null, null, $invoicePaymentId, $paymentMethodId);
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
        ?string         $note = null,
        ?int            $paymentMethodId = null
    ): SafeTransaction {
        $this->guardAgainstOverdraft($safe->id, $currencyId, $amount, $paymentMethodId);
        return $this->applyTransaction($safe, 'supplier_payment', $currencyId, $amount, $userId, null, $note, null, null, null, $payment->id, null, $paymentMethodId);
    }

    /**
     * Reverses a supplier payment when its purchase invoice is cancelled —
     * money flows back INTO the same safe/currency it was originally paid
     * from (direction 'in', unlike 'supplier_payment' which is 'out'), so no
     * overdraft guard applies here. Links back to the same SupplierPayment
     * row via `supplier_payment_id` — no new FK column needed.
     */
    public function recordSupplierPaymentRefund(
        Safe            $safe,
        SupplierPayment $payment,
        int             $currencyId,
        float           $amount,
        int             $userId,
        ?string         $note = null,
        ?int            $paymentMethodId = null
    ): SafeTransaction {
        return $this->applyTransaction($safe, 'supplier_payment_refund', $currencyId, $amount, $userId, null, $note, null, null, null, $payment->id, null, $paymentMethodId);
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
        ?int    $supplierPaymentId = null,
        ?int    $invoicePaymentId  = null,
        ?int    $paymentMethodId   = null
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
            'invoice_payment_id'   => $invoicePaymentId,
            'payment_method_id'    => $paymentMethodId,
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

    /**
     * Sub Safes: when $paymentMethodId is given, guards the CHILD safe's
     * balance instead of the parent's — a child balance is always <= the
     * parent's, so this is strictly the tighter check. The lockForUpdate()
     * on the parent SafeBalance row still runs first and is held for the rest
     * of the enclosing DB::transaction() (every write to this safe+currency
     * takes the same lock in updateBalance()), which is what serializes
     * concurrent child-safe writes — no separate lock needed for the derived sum.
     */
    private function guardAgainstOverdraft(int $safeId, int $currencyId, float $amount, ?int $paymentMethodId = null): void
    {
        $balance = SafeBalance::where('safe_id', $safeId)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        $current = $balance ? (float) $balance->balance : 0;

        if ($current < $amount) {
            abort(422, "الرصيد غير كافٍ. الرصيد الحالي: {$current}");
        }

        if ($paymentMethodId !== null) {
            $childBalance = $this->childBalance($safeId, $currencyId, $paymentMethodId);
            if ($childBalance < $amount) {
                $methodName = \App\Models\PaymentMethod::find($paymentMethodId)?->name ?? 'وسيلة الدفع المحددة';
                abort(422, "الرصيد غير كافٍ في \"{$methodName}\". الرصيد الحالي: " . round($childBalance, 2));
            }
        }
    }

    /** The exact same COALESCE(direct, indirect-via-InvoicePayment) matching used in getBalancesByPaymentMethod(), scoped to one method. */
    private function childBalance(int $safeId, int $currencyId, int $paymentMethodId): float
    {
        return (float) (SafeTransaction::query()
            ->where('safe_transactions.safe_id', $safeId)
            ->where('safe_transactions.currency_id', $currencyId)
            ->leftJoin('invoice_payments', 'safe_transactions.invoice_payment_id', '=', 'invoice_payments.id')
            ->where(function ($q) use ($paymentMethodId) {
                $q->where('safe_transactions.payment_method_id', $paymentMethodId)
                  ->orWhere(function ($q2) use ($paymentMethodId) {
                      $q2->whereNull('safe_transactions.payment_method_id')
                         ->where('invoice_payments.payment_method_id', $paymentMethodId);
                  });
            })
            ->selectRaw('COALESCE(SUM(CASE WHEN safe_transactions.direction = "in" THEN safe_transactions.amount ELSE -safe_transactions.amount END), 0) as balance')
            ->value('balance') ?? 0);
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
