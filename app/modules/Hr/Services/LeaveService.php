<?php

namespace App\Modules\Hr\Services;

use App\Models\LeaveCashOut;
use App\Models\LeaveRequest;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave requests + balance.
 *
 * Monthly allowance is per employee (users.monthly_leave_allowance), resetting
 * each month. On approval,
 * the days that fit the remaining balance are PAID; any excess becomes UNPAID
 * (unpaid_days), which the payroll engine turns into unpaid-leave deductions.
 * paid/unpaid split is frozen at approval so later changes never rewrite it.
 *
 * Approval automatically stamps every day of the leave onto the employee's
 * Schedule (type=leave, published) and Attendance (status=leave) — the single
 * source of truth stays the leave request itself; those stamped rows are just
 * a projection, exactly like Transferred cells are a projection of
 * employee_transfers. Ending a leave early re-projects the freed tail back to
 * plain "work" days.
 */
class LeaveService
{
    /** Notify the employee when their remaining balance falls to/below this. */
    public const LOW_BALANCE_THRESHOLD = 1;

    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
        private ScheduleService $schedule,
        private AttendanceService $attendance,
    ) {}

    public function create(User $employee, array $data): LeaveRequest
    {
        $start = Carbon::parse($data['start_date']);
        $end   = Carbon::parse($data['end_date']);
        $days  = $start->diffInDays($end) + 1; // inclusive

        $leave = LeaveRequest::create([
            'user_id'    => $employee->id,
            'start_date' => $start->toDateString(),
            'end_date'   => $end->toDateString(),
            'days'       => $days,
            'type'       => $data['type'] ?? 'annual',
            'reason_id'  => $data['reason_id'] ?? null,
            'reason'     => $data['reason'] ?? null,
            'status'     => LeaveRequest::PENDING,
        ]);

        $this->audit->log('leave.created', $leave, null, $leave->only(['user_id', 'start_date', 'end_date', 'days']));
        // Notify admins + the employee's branch manager that a request awaits review.
        $this->notifyReviewers($employee, 'طلب إجازة جديد', "{$employee->name}: {$days} يوم ({$start->toDateString()} → {$end->toDateString()})", '/dashboard/hr/leaves');

        return $leave;
    }

    /**
     * Cumulative (carry-over) leave balance as of a given date — the single
     * source of truth for both display (section 4/5) and the paid/unpaid
     * split at approval time. Unused earned leave is never reset to zero
     * each month: earned-to-date accrues monthlyAccrual × whole months
     * employed (from hire_date, inclusive of the current month), and only
     * PAID usage + cash-outs reduce it. `year`/`month` are accepted for
     * backward compatibility with existing callers but only their combined
     * date (defaulting to "now") is used — "as of" that date, not a
     * per-month reset window.
     */
    public function balance(User $employee, ?int $year = null, ?int $month = null): array
    {
        $asOf = $year && $month ? Carbon::create($year, $month, 1)->endOfMonth() : now();

        $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date) : ($employee->created_at ?? $asOf);
        // Carbon 3's diffInMonths() returns a FLOAT by default — truncate to
        // whole months employed (a partial current month still counts as 1).
        $monthsEmployed = max(1, (int) floor($hireDate->diffInMonths($asOf)) + 1);
        $monthlyAccrual = (float) $employee->monthly_leave_allowance;
        $earnedToDate = round($monthlyAccrual * $monthsEmployed, 2);

        $usedPaid = (float) LeaveRequest::where('user_id', $employee->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereDate('start_date', '<=', $asOf->toDateString())
            ->sum('paid_days');

        $cashedOut = (float) LeaveCashOut::where('user_id', $employee->id)
            ->whereDate('date', '<=', $asOf->toDateString())
            ->sum('days');

        $remaining = round($earnedToDate - $usedPaid - $cashedOut, 2);

        return [
            'as_of'           => $asOf->toDateString(),
            'monthly_accrual' => $monthlyAccrual,
            'earned_to_date'  => $earnedToDate,
            'used'            => round($usedPaid, 2),
            'cashed_out'      => round($cashedOut, 2),
            'remaining'       => max(0.0, $remaining),
            // Legacy shape kept for existing callers that only read 'allowance'.
            'allowance'       => $monthlyAccrual,
        ];
    }

    public function approve(LeaveRequest $leave, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $note) {
            $employee  = $leave->user;
            $start     = Carbon::parse($leave->start_date);
            $end       = Carbon::parse($leave->end_date);
            // Reason-based leave (Admin-configured LeaveReason): the two
            // flags are independent and BOTH may be on, applied sequentially
            // — balance is consumed FIRST, and only the days left over (the
            // excess beyond what the balance could cover) are ever eligible
            // for a salary deduction. `paid_days` = days covered by the
            // balance; `unpaid_days` = days NOT covered by the balance (the
            // excess). PayrollService reads `unpaid_days` — not the raw
            // `days` — when computing this reason's own salary deduction, so
            // a day covered by the balance is never ALSO salary-deducted.
            // If deducts_leave_balance is off, nothing is covered (paid=0)
            // and every day is "excess" — deducts_salary then decides
            // whether that excess is actually charged. If deducts_salary is
            // off, PayrollService's reason-loop simply never reads this
            // reason's leaves, regardless of how many days are "excess".
            // Only whole days can be "spent" from a (possibly fractional,
            // carried-over) balance — any leftover fraction stays unused.
            if ($leave->reason_id && $leave->leaveReason) {
                if ($leave->leaveReason->deducts_leave_balance) {
                    $remaining = (int) floor($this->balance($employee)['remaining']);
                    $paid   = min($leave->days, $remaining);
                    $unpaid = $leave->days - $paid;
                } else {
                    $paid   = 0;
                    $unpaid = $leave->days;
                }
            } else {
                // Legacy/free-type leave — same cumulative (carry-over) balance,
                // no longer a monthly reset (section 10).
                $remaining = (int) floor($this->balance($employee)['remaining']);
                $paid   = min($leave->days, $remaining);
                $unpaid = $leave->days - $paid;
            }

            $leave->update([
                'status'      => LeaveRequest::APPROVED,
                'paid_days'   => $paid,
                'unpaid_days' => $unpaid,
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $this->audit->log('leave.approved', $leave,
                ['status' => LeaveRequest::PENDING],
                ['status' => LeaveRequest::APPROVED, 'paid_days' => $paid, 'unpaid_days' => $unpaid],
            );

            // Auto-update the schedule + attendance for every day of the leave —
            // no manual editing required (Phase 10 rule #1).
            $this->schedule->applyLeaveRange($employee->id, $start, $end);
            $this->attendance->markLeaveRange($employee->id, $start, $end);

            // Notify the employee of the outcome.
            $msg = "تمت الموافقة على إجازتك ({$leave->days} يوم)"
                . ($unpaid > 0 ? " — منها {$unpaid} يوم بدون أجر (تجاوز الرصيد)" : '');
            $this->notifications->notify([$employee->id], 'leave', 'الموافقة على الإجازة', $msg,
                ['type' => 'leave', 'leave_id' => $leave->id, 'route' => '/dashboard/my-leave']);

            // Low-balance / exceeded warnings — cumulative (carry-over) balance.
            $after = $this->balance($employee);
            if ($unpaid > 0) {
                $this->notifications->notify([$employee->id], 'leave', 'تجاوز رصيد الإجازات',
                    "لقد تجاوزت رصيد إجازاتك المتاح؛ الأيام الزائدة ستُخصم كإجازة بدون أجر.",
                    ['type' => 'leave', 'route' => '/dashboard/my-leave']);
                $this->notifyReviewers($employee, 'موظف تجاوز رصيد الإجازات', "{$employee->name} تجاوز رصيد الإجازات المتاح.", '/dashboard/hr/leaves');
            } elseif ($after['remaining'] <= self::LOW_BALANCE_THRESHOLD) {
                $this->notifications->notify([$employee->id], 'leave', 'رصيد الإجازات منخفض',
                    "تبقّى لديك {$after['remaining']} يوم إجازة فقط.",
                    ['type' => 'leave', 'route' => '/dashboard/my-leave']);
            }

            return $leave->fresh(['user:id,name']);
        });
    }

    public function reject(LeaveRequest $leave, ?string $note = null): LeaveRequest
    {
        $leave->update([
            'status'      => LeaveRequest::REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $this->audit->log('leave.rejected', $leave, ['status' => LeaveRequest::PENDING], ['status' => LeaveRequest::REJECTED]);
        $this->notifications->notify([$leave->user_id], 'leave', 'رفض طلب الإجازة',
            'تم رفض طلب إجازتك.' . ($note ? " السبب: {$note}" : ''),
            ['type' => 'leave', 'leave_id' => $leave->id, 'route' => '/dashboard/my-leave']);

        return $leave->fresh(['user:id,name']);
    }

    /** Employee cancels their OWN still-pending request (nothing to revert yet — never applied). */
    public function cancel(LeaveRequest $leave): LeaveRequest
    {
        if ($leave->status !== LeaveRequest::PENDING) {
            throw ValidationException::withMessages(['leave' => 'لا يمكن إلغاء طلب تمت مراجعته بالفعل.']);
        }

        $leave->update(['status' => LeaveRequest::CANCELLED]);
        $this->audit->log('leave.cancelled', $leave, ['status' => LeaveRequest::PENDING], ['status' => LeaveRequest::CANCELLED]);
        $this->notifyReviewers($leave->user, 'تم إلغاء طلب إجازة', "{$leave->user->name} ألغى طلب إجازته ({$leave->start_date->toDateString()} → {$leave->end_date->toDateString()}).", '/dashboard/hr/leaves');

        return $leave->fresh(['user:id,name']);
    }

    /**
     * End an already-APPROVED leave early. Permission (admin: any; manager:
     * own-branch non-managers only) is checked by the caller (controller) —
     * this method only performs the state change once authorized.
     */
    public function endEarly(LeaveRequest $leave, string $newEndDateStr, User $actor): LeaveRequest
    {
        if ($leave->status !== LeaveRequest::APPROVED) {
            throw ValidationException::withMessages(['leave' => 'يمكن إنهاء الإجازات المعتمدة فقط.']);
        }

        $originalEnd = Carbon::parse($leave->end_date);
        $newEnd      = Carbon::parse($newEndDateStr);
        $start       = Carbon::parse($leave->start_date);

        if ($newEnd->lt($start) || $newEnd->gte($originalEnd)) {
            throw ValidationException::withMessages(['end_date' => 'يجب أن يكون تاريخ الإنهاء المبكر بين تاريخ البداية وقبل تاريخ النهاية الأصلي.']);
        }

        return DB::transaction(function () use ($leave, $newEnd, $originalEnd, $start, $actor) {
            $employee  = $leave->user;
            $oldDays   = $leave->days;
            $newDays   = $start->diffInDays($newEnd) + 1;
            $newPaid   = min($newDays, (int) $leave->paid_days);
            $newUnpaid = max(0, $newDays - $newPaid);

            $leave->update([
                'original_end_date' => $leave->original_end_date ?? $originalEnd->toDateString(),
                'end_date'          => $newEnd->toDateString(),
                'days'              => $newDays,
                'paid_days'         => $newPaid,
                'unpaid_days'       => $newUnpaid,
                'ended_early_at'    => now(),
                'ended_by'          => $actor->id,
            ]);

            $this->audit->log('leave.ended_early', $leave,
                ['end_date' => $originalEnd->toDateString(), 'days' => $oldDays],
                ['end_date' => $newEnd->toDateString(), 'days' => $newDays, 'ended_by' => $actor->id],
            );

            // Restore the freed tail (newEnd+1 .. originalEnd) to working days.
            $restoreFrom = $newEnd->copy()->addDay();
            $this->schedule->restoreWorkRange($employee->id, $restoreFrom, $originalEnd);
            $this->attendance->clearLeaveRange($employee->id, $restoreFrom, $originalEnd);

            $msg = "تم إنهاء إجازتك مبكرًا بتاريخ {$newEnd->toDateString()} — عادت الأيام من "
                . "{$restoreFrom->toDateString()} إلى {$originalEnd->toDateString()} أيام عمل.";

            $this->notifications->notify([$employee->id], 'leave', 'تم إنهاء الإجازة مبكرًا', $msg,
                ['type' => 'leave', 'leave_id' => $leave->id, 'route' => '/dashboard/my-leave']);
            $this->notifyReviewers($employee, 'تم إنهاء إجازة موظف مبكرًا',
                "{$employee->name}: أُنهيت إجازته مبكرًا بتاريخ {$newEnd->toDateString()} بواسطة {$actor->name}.",
                '/dashboard/hr/leaves');

            return $leave->fresh(['user:id,name']);
        });
    }

    /** Notify admins + the employee's branch manager. */
    private function notifyReviewers(User $employee, string $title, string $message, ?string $route = null): void
    {
        $data = ['type' => 'leave'];
        if ($route) {
            $data['route'] = $route;
        }

        $managerId = $employee->shop_id ? Shop::where('id', $employee->shop_id)->value('manager_id') : null;
        if ($managerId) {
            $this->notifications->notify([$managerId], 'leave', $title, $message, $data);
        }
        $this->notifications->notifyAdmins('leave', $title, $message, $data);
    }
}
