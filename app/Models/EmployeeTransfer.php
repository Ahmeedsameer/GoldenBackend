<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A temporary transfer of an employee from their primary branch to another
 * branch for a bounded period.
 *
 * Lifecycle: draft → scheduled → active → completed  (or cancelled).
 */
class EmployeeTransfer extends Model
{
    public const DRAFT     = 'draft';
    public const SCHEDULED = 'scheduled';
    public const ACTIVE    = 'active';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    /** Statuses that actually move the employee (used to resolve active branch). */
    public const EFFECTIVE_STATUSES = [self::SCHEDULED, self::ACTIVE, self::COMPLETED];

    protected $fillable = [
        'user_id',
        'primary_branch_id',
        'temporary_branch_id',
        'start_date',
        'end_date',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approval_date',
        'notes',
    ];

    protected $casts = [
        'start_date'    => 'date',
        'end_date'      => 'date',
        'approval_date' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'primary_branch_id');
    }

    public function temporaryBranch(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'temporary_branch_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** True when this transfer moves the employee on the given date. */
    public function coversDate(Carbon $date): bool
    {
        return in_array($this->status, self::EFFECTIVE_STATUSES, true)
            && $date->betweenIncluded($this->start_date, $this->end_date);
    }
}
