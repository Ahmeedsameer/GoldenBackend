<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable monthly payroll record. Never updated after generation (except the
 * status → paid transition). All financial history is preserved verbatim.
 */
class Payroll extends Model
{
    public const GENERATED = 'generated';
    public const PAID      = 'paid';

    protected $fillable = [
        'user_id',
        'period_year',
        'period_month',
        'base_salary',
        'personal_commission_percent',
        'personal_sales_total',
        'personal_commission_amount',
        'branch_commission_amount',
        'bonus_total',
        'penalty_total',
        'advance_deduction',
        'gross',
        'total_deductions',
        'net_salary',
        'working_days',
        'present_days',
        'absent_days',
        'late_days',
        'half_days',
        'unpaid_leave_days',
        'status',
        'is_locked',
        'locked_by',
        'locked_at',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'base_salary'                 => 'decimal:2',
        'personal_commission_percent' => 'decimal:2',
        'personal_sales_total'        => 'decimal:2',
        'personal_commission_amount'  => 'decimal:2',
        'branch_commission_amount'    => 'decimal:2',
        'bonus_total'                 => 'decimal:2',
        'penalty_total'               => 'decimal:2',
        'advance_deduction'           => 'decimal:2',
        'gross'                       => 'decimal:2',
        'total_deductions'            => 'decimal:2',
        'net_salary'                  => 'decimal:2',
        'generated_at'                => 'datetime',
        'is_locked'                   => 'boolean',
        'locked_at'                   => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
