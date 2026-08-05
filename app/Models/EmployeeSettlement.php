<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable Final Settlement snapshot, created once when an employee's
 * employment ends (see EmployeeService::endEmployment()). Never recalculated
 * or overwritten — every field is a frozen copy of the numbers shown to the
 * admin in the settlement preview at confirmation time. An employee may have
 * several of these over time (rehired, left again, etc.).
 */
class EmployeeSettlement extends Model
{
    protected $fillable = [
        'settlement_number',
        'employee_id',
        'prepared_by',
        'employment_status',
        'leaving_date',
        'worked_days',
        'daily_rate',
        'basic_salary',
        'salary_earned',
        'sales_commission',
        'branch_profit',
        'bonuses',
        'deductions',
        'salary_advances',
        'final_settlement',
        'notes',
    ];

    protected $casts = [
        'leaving_date'      => 'date',
        'worked_days'       => 'integer',
        'daily_rate'        => 'decimal:2',
        'basic_salary'      => 'decimal:2',
        'salary_earned'     => 'decimal:2',
        'sales_commission'  => 'decimal:2',
        'branch_profit'     => 'decimal:2',
        'bonuses'           => 'decimal:2',
        'deductions'        => 'decimal:2',
        'salary_advances'   => 'decimal:2',
        'final_settlement'  => 'decimal:2',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
