<?php

namespace App\Modules\Stock\Services;

use App\Models\Supplier;
use App\Models\SupplierContact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SupplierService
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Supplier::query()->withCount('supplies');

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function findOrFail(int $id): Supplier
    {
        return Supplier::withCount('supplies')->findOrFail($id);
    }

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);
        return $supplier->fresh();
    }

    public function delete(Supplier $supplier): void
    {
        if ($supplier->supplies()->exists()) {
            abort(422, 'لا يمكن حذف المورد لوجود توريدات مرتبطة به');
        }

        $supplier->delete();
    }

    // ── Contacts — no limit per supplier ──────────────────────────────────────

    public function listContacts(Supplier $supplier): \Illuminate\Database\Eloquent\Collection
    {
        return $supplier->contacts()->latest()->get();
    }

    public function addContact(Supplier $supplier, array $data): SupplierContact
    {
        return $supplier->contacts()->create($data);
    }

    public function updateContact(SupplierContact $contact, array $data): SupplierContact
    {
        $contact->update($data);
        return $contact->fresh();
    }

    public function deleteContact(SupplierContact $contact): void
    {
        $contact->delete();
    }

    // ── Ledger / balance ───────────────────────────────────────────────────────
    // Read-only aggregate, same "never writes" convention as SupplierProfileService.

    /**
     * Outstanding balance = opening_balance (pre-existing debt from before this
     * system tracked payments) + every invoice's own remaining_amount. Never a
     * single merged number pulled from anywhere else — always summed fresh
     * from each invoice's independent balance (see Supply::remaining_amount),
     * exactly per the "never merge balances" rule.
     */
    public function ledger(Supplier $supplier): array
    {
        $invoices = $supplier->supplies()->with('items.product:id,name', 'payments.safe.shop:id,name', 'payments.currency', 'payments.user:id,name')->latest('date')->latest('id')->get();

        $totalInvoiced = round((float) $invoices->sum('total_amount'), 2);
        $totalPaid     = round((float) $invoices->sum('paid_amount'), 2);
        $currentCredit = round((float) $invoices->sum('remaining_amount'), 2);
        $openingBalance = (float) $supplier->opening_balance;
        $outstandingBalance = round($openingBalance + $currentCredit, 2);

        return [
            'opening_balance'      => $openingBalance,
            'total_invoiced'       => $totalInvoiced,
            'total_paid'           => $totalPaid,
            'current_credit'       => $currentCredit,       // sum of unpaid invoice balances only
            'outstanding_balance'  => $outstandingBalance,  // opening_balance + current_credit
            'invoices' => $invoices->map(fn ($s) => [
                'id' => $s->id, 'invoice_number' => $s->invoice_number, 'date' => $s->date,
                'items_subtotal' => $s->items_subtotal, 'discount' => (float) $s->discount, 'tax' => (float) $s->tax,
                'total_amount' => $s->total_amount, 'paid_amount' => (float) $s->paid_amount,
                'remaining_amount' => $s->remaining_amount, 'payment_status' => $s->payment_status,
                'products' => $s->items->pluck('product.name')->filter()->unique()->values(),
            ])->values(),
            'payments' => $invoices->flatMap(fn ($s) => $s->payments->map(fn ($p) => [
                'id' => $p->id, 'supply_id' => $s->id, 'invoice_number' => $s->invoice_number,
                'amount' => (float) $p->amount, 'date' => $p->date,
                'safe' => $p->safe?->shop?->name ?? 'الخزنة الرئيسية',
                'currency' => $p->currency?->code, 'user' => $p->user?->name, 'note' => $p->note,
            ]))->sortByDesc('date')->values(),
        ];
    }

    /**
     * The same opening_balance + Σ(remaining_amount) computation as ledger()
     * above, but one row per supplier (no per-invoice/per-payment detail) —
     * powers the cross-supplier "Supplier Balance" / "Outstanding Suppliers"
     * reports. `$onlyOutstanding` filters to balance > 0 for the latter.
     */
    public function balancesSummary(bool $onlyOutstanding = false): \Illuminate\Support\Collection
    {
        $rows = Supplier::query()
            ->with('supplies.items', 'supplies.payments')
            ->get()
            ->map(function (Supplier $s) {
                $currentCredit = round((float) $s->supplies->sum('remaining_amount'), 2);
                $outstanding = round((float) $s->opening_balance + $currentCredit, 2);
                return [
                    'id' => $s->id, 'name' => $s->name, 'phone' => $s->phone,
                    'opening_balance' => (float) $s->opening_balance,
                    'total_invoiced' => round((float) $s->supplies->sum('total_amount'), 2),
                    'total_paid' => round((float) $s->supplies->sum('paid_amount'), 2),
                    'current_credit' => $currentCredit,
                    'outstanding_balance' => $outstanding,
                    'invoice_count' => $s->supplies->count(),
                ];
            });

        return $onlyOutstanding ? $rows->filter(fn ($r) => $r['outstanding_balance'] > 0)->values() : $rows->values();
    }
}
