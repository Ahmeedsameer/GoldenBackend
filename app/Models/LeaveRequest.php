<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    public const PENDING   = 'pending';
    public const APPROVED  = 'approved';
    public const REJECTED  = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'original_end_date',
        'days',
        'type',
        'status',
        'reason',
        'paid_days',
        'unpaid_days',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'ended_early_at',
        'ended_by',
    ];

    protected $casts = [
        'start_date'        => 'date',
        'end_date'          => 'date',
        'original_end_date' => 'date',
        'reviewed_at'       => 'datetime',
        'ended_early_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }
}
