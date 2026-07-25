<?php

namespace App\Modules\Stock\Services;

use App\Models\Safe;
use App\Models\Supply;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Modules\Safe\Services\SafeService;
use Illuminate\Support\Facades\DB;

/**
 * Registers a payment against exactly ONE purchase invoice (Supply) — never
 * a free-floating "pay this supplier X" — so every invoice keeps its own
 * independent remaining balance even when a supplier has several open
 * invoices (see Supply::remaining_amount). Money movement itself is a
 * SafeTransaction via the existing SafeService — no parallel cash system.
 */
class SupplierPaymentService
{
    public function __construct(private SafeService $safeService) {}

    public function pay(Supply $invoice, Safe $safe, int $currencyId, float $amount, User $user, ?string $note = null): SupplierPayment
    {
        if ($amount <= 0) {
            abort(422, 'مبلغ الدفعة يجب أن يكون أكبر من صفر');
        }

        // Recomputed fresh (never trusted from the client) — total/paid_amount
        // could have changed since the page was loaded.
        $invoice->refresh()->load('items');
        $remaining = $invoice->remaining_amount;
        if (round($amount, 2) > round($remaining, 2)) {
            abort(422, "المبلغ المدخل ({$amount}) أكبر من المتبقي على هذه الفاتورة ({$remaining})");
        }

        return DB::transaction(function () use ($invoice, $safe, $currencyId, $amount, $user, $note) {
            $payment = SupplierPayment::create([
                'supply_id'   => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'safe_id'     => $safe->id,
                'currency_id' => $currencyId,
                'amount'      => $amount,
                'date'        => now()->toDateString(),
                'user_id'     => $user->id,
                'note'        => $note,
            ]);

            $invoice->increment('paid_amount', $amount);

            $supplierName = $invoice->supplier?->name ?? '';
            $this->safeService->recordSupplierPayment(
                $safe, $payment, $currencyId, $amount, $user->id,
                $note ?? "دفعة لفاتورة توريد {$invoice->invoice_number} — المورد: {$supplierName}",
            );

            return $payment->load(['safe.safeType', 'safe.shop:id,name', 'currency', 'user:id,name']);
        });
    }

    public function list(array $filters, int $perPage = 25): mixed
    {
        $query = SupplierPayment::query()
            ->with(['supply:id,invoice_number,supplier_id', 'supplier:id,name', 'safe.shop:id,name', 'currency', 'user:id,name']);

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }
        if (!empty($filters['supply_id'])) {
            $query->where('supply_id', $filters['supply_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query->latest('date')->latest('id')->paginate($perPage);
    }
}
