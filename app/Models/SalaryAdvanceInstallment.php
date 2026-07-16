<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvanceInstallment extends Model
{
    public const PENDING   = 'pending';
    public const PAID      = 'paid';
    public const SKIPPED   = 'skipped';
    public const CANCELLED = 'cancelled';

    /** 'due' is never stored — see SalaryAdvance model / controllers: an
     *  installment is "due" when it's still PENDING and its period is the
     *  current calendar month. Computed on read, not a persisted state. */
    public const DUE = 'due';

    protected $fillable = [
        'salary_advance_id',
        'period_year',
        'period_month',
        'sequence',
        'planned_amount',
        'status',
        'payroll_id',
        'deducted_at',
        'reminded_at',
    ];

    protected $casts = [
        'planned_amount' => 'decimal:2',
        'deducted_at'    => 'datetime',
        'reminded_at'    => 'datetime',
    ];

    protected $appends = ['effective_status'];

    /** Read-only view of status that surfaces the derived DUE state without persisting it. */
    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === self::PENDING) {
            $now = now();
            if ((int) $this->period_year === (int) $now->year && (int) $this->period_month === (int) $now->month) {
                return self::DUE;
            }
        }
        return $this->status;
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class, 'salary_advance_id');
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(Payroll::class);
    }
}
