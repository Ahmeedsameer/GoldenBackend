<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeTransaction extends Model
{
    /**
     * Direction is always derived from type — never set freely by caller.
     */
    public const DIRECTION_MAP = [
        'sale'                 => 'in',
        'refund'               => 'out',
        'admin_deposit'        => 'in',
        'admin_withdrawal'     => 'out',
        'manager_deposit'      => 'in',
        'manager_expense'      => 'out',
        'transfer_in'          => 'in',
        'transfer_out'         => 'out',
        'advance_disbursement'     => 'out',
        'advance_repayment'        => 'in',
        'supplier_payment'         => 'out',
        'supplier_payment_refund'  => 'in',
        'bank_charge'              => 'out',
        'bank_charge_reversal'     => 'in',
    ];

    protected $fillable = [
        'safe_id',
        'type',
        'direction',
        'currency_id',
        'amount',
        'reason_id',
        'note',
        'invoice_id',
        'transfer_id',
        'salary_advance_id',
        'supplier_payment_id',
        'invoice_payment_id',
        'user_id',
    ];

    protected $casts = ['amount' => 'decimal:2'];

    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(TransactionReason::class, 'reason_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(SafeTransfer::class, 'transfer_id');
    }

    public function salaryAdvance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class);
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    /** The exact payment LINE that generated this transaction — disambiguates which of an invoice's several payment lines this row belongs to (Safe History traceability). */
    public function invoicePayment(): BelongsTo
    {
        return $this->belongsTo(InvoicePayment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
