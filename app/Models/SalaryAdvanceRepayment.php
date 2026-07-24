<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvanceRepayment extends Model
{
    protected $fillable = [
        'salary_advance_id',
        'safe_id',
        'amount',
        'date',
        'recorded_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date'   => 'date',
    ];

    public function advance(): BelongsTo
    {
        return $this->belongsTo(SalaryAdvance::class, 'salary_advance_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Which Safe/Custody received this specific repayment — chosen by the admin at recording time. */
    public function safe(): BelongsTo
    {
        return $this->belongsTo(Safe::class);
    }
}
