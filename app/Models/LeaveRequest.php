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

    /**
     * The single source of truth for "is this user on leave today" — an
     * APPROVED leave whose [start_date, end_date] range includes today
     * (both bounds inclusive, so a leave ending today still counts as
     * active for the whole day). Centralized here so login (AuthController),
     * route access (CheckRole), and sale creation (SalesService) all apply
     * the exact same rule instead of three copies drifting apart.
     *
     * "Today" comes from Carbon's default (config('app.timezone')), never
     * a client-supplied date — the caller must never pass one in.
     */
    public static function activeForUserToday(int $userId): ?self
    {
        return static::where('user_id', $userId)
            ->where('status', self::APPROVED)
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->first();
    }

    /**
     * THE single "is this user on leave today, and why" check — the one every
     * caller (login, CheckRole, sale creation) should use instead of querying
     * leave_requests directly. Returns a ready-to-show Arabic message, or null
     * when the user is not on leave.
     *
     * Leave shows up in TWO independent places in this system, and a user can
     * be on leave via either without the other ever being touched:
     *   1. An APPROVED LeaveRequest covering today (the request/approval flow,
     *      checked by activeForUserToday() above).
     *   2. A published ScheduleEntry with type=leave for today — the Schedule
     *      grid lets a manager mark a day "إجازة" directly, with NO LeaveRequest
     *      row created at all (see ScheduleController::upsert/bulkUpsert).
     *      Approving a LeaveRequest also stamps this same table (see
     *      ScheduleService::applyLeaveRange()), so an approved request is
     *      normally visible here too — but the reverse never happens, so
     *      skipping this check misses every leave set straight from the
     *      Schedule screen.
     * Both must be checked or "on leave" can silently mean nothing at all.
     */
    public static function leaveMessageFor(int $userId): ?string
    {
        if ($leave = self::activeForUserToday($userId)) {
            return "لا يمكنك إجراء عمليات بيع أثناء الإجازة حتى {$leave->end_date->toDateString()}.";
        }

        $onScheduleLeave = ScheduleEntry::where('user_id', $userId)
            ->whereDate('date', today())
            ->where('type', ScheduleEntry::LEAVE)
            ->where('is_published', true)
            ->exists();

        if ($onScheduleLeave) {
            return 'لا يمكنك إجراء عمليات بيع أثناء الإجازة المسجلة اليوم في جدول العمل.';
        }

        return null;
    }
}
