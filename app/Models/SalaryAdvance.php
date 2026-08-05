<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryAdvance extends Model
{
    public const PENDING   = 'pending';
    public const REJECTED  = 'rejected';
    public const ACTIVE    = 'active';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const MODE_FIXED_AMOUNT = 'fixed_amount';
    public const MODE_FIXED_MONTHS = 'fixed_months';
    public const MODE_CUSTOM       = 'custom';
    /** Admin gives Approved Amount + Start/End month; split evenly across that inclusive range. */
    public const MODE_DATE_RANGE   = 'date_range';

    protected $fillable = [
        'user_id',
        'requested_amount',
        'approved_amount',
        'reason',
        'notes',
        'request_date',
        'status',
        'installment_mode',
        'paid_amount',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'completed_at',
        'paying_safe_id',
        'default_repayment_safe_id',
    ];

    protected $casts = [
        'requested_amount' => 'decimal:2',
        'approved_amount'  => 'decimal:2',
        'paid_amount'      => 'decimal:2',
        'request_date'     => 'date',
        'reviewed_at'      => 'datetime',
        'completed_at'     => 'datetime',
    ];

    protected $appends = ['remaining_balance'];

    public function getRemainingBalanceAttribute(): float
    {
        if ($this->approved_amount === null) {
            return 0.0;
        }
        return round((float) $this->approved_amount - (float) $this->paid_amount, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Which Safe/Custody actually paid this advance out — chosen by the admin at approval time. */
    public function payingSafe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'paying_safe_id');
    }

    /** Where future installment repayments land by default — see SalaryAdvanceService::changeDefaultSafe(). */
    public function defaultRepaymentSafe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'default_repayment_safe_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(SalaryAdvanceInstallment::class)->orderBy('sequence');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(SalaryAdvanceRepayment::class)->latest('date');
    }
}
