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
        'sale'              => 'in',
        'refund'            => 'out',
        'admin_deposit'     => 'in',
        'admin_withdrawal'  => 'out',
        'manager_deposit'   => 'in',
        'manager_expense'   => 'out',
        'transfer_in'       => 'in',
        'transfer_out'      => 'out',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
