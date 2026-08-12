<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveReason extends Model
{
    public const MODE_DAILY_FRACTION = 'daily_fraction';
    public const MODE_FIXED          = 'fixed';

    protected $fillable = [
        'name',
        'deducts_leave_balance',
        'deducts_salary',
        'deduction_mode',
        'deduction_value',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'deducts_leave_balance' => 'boolean',
        'deducts_salary'        => 'boolean',
        'deduction_value'       => 'decimal:4',
        'is_active'             => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'reason_id');
    }
}
