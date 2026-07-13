<?php

namespace App\Modules\Hr\Services;

use App\Models\LeaveRequest;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Leave requests + balance.
 *
 * Monthly allowance is per employee (users.monthly_leave_allowance), resetting
 * each month. On approval,
 * the days that fit the remaining balance are PAID; any excess becomes UNPAID
 * (unpaid_days), which the payroll engine turns into unpaid-leave deductions.
 * paid/unpaid split is frozen at approval so later changes never rewrite it.
 */
class LeaveService
{
    /** Notify the employee when their remaining balance falls to/below this. */
    public const LOW_BALANCE_THRESHOLD = 1;

    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
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
            'reason'     => $data['reason'] ?? null,
            'status'     => LeaveRequest::PENDING,
        ]);

        $this->audit->log('leave.created', $leave, null, $leave->only(['user_id', 'start_date', 'end_date', 'days']));
        // Notify admins + the employee's branch manager that a request awaits review.
        $this->notifyReviewers($employee, 'طلب إجازة جديد', "{$employee->name}: {$days} يوم ({$start->toDateString()} → {$end->toDateString()})");

        return $leave;
    }

    /** Remaining PAID balance for the employee in a given MONTH (resets monthly). */
    public function balance(User $employee, ?int $year = null, ?int $month = null): array
    {
        $year  ??= (int) now()->year;
        $month ??= (int) now()->month;

        $usedPaid = (int) LeaveRequest::where('user_id', $employee->id)
            ->where('status', LeaveRequest::APPROVED)
            ->whereYear('start_date', $year)
            ->whereMonth('start_date', $month)
            ->sum('paid_days');

        $allowance = (int) $employee->monthly_leave_allowance;

        return [
            'year'      => $year,
            'month'     => $month,
            'allowance' => $allowance,
            'used'      => $usedPaid,
            'remaining' => max(0, $allowance - $usedPaid),
        ];
    }

    public function approve(LeaveRequest $leave, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($leave, $note) {
            $employee  = $leave->user;
            $start     = Carbon::parse($leave->start_date);
            $year      = (int) $start->year;
            $month     = (int) $start->month;
            $remaining = $this->balance($employee, $year, $month)['remaining'];

            $paid   = min($leave->days, $remaining);
            $unpaid = $leave->days - $paid;

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

            // Notify the employee of the outcome.
            $msg = "تمت الموافقة على إجازتك ({$leave->days} يوم)"
                . ($unpaid > 0 ? " — منها {$unpaid} يوم بدون أجر (تجاوز الرصيد)" : '');
            $this->notifications->notify([$employee->id], 'leave', 'الموافقة على الإجازة', $msg, ['type' => 'leave', 'leave_id' => $leave->id]);

            // Low-balance / exceeded warnings (monthly).
            $after = $this->balance($employee, $year, $month);
            if ($unpaid > 0) {
                $this->notifications->notify([$employee->id], 'leave', 'تجاوز رصيد الإجازات',
                    "لقد تجاوزت رصيد إجازاتك الشهري؛ الأيام الزائدة ستُخصم كإجازة بدون أجر.", ['type' => 'leave']);
                $this->notifyReviewers($employee, 'موظف تجاوز رصيد الإجازات', "{$employee->name} تجاوز رصيد الإجازات الشهري.");
            } elseif ($after['remaining'] <= self::LOW_BALANCE_THRESHOLD) {
                $this->notifications->notify([$employee->id], 'leave', 'رصيد الإجازات منخفض',
                    "تبقّى لديك {$after['remaining']} يوم إجازة فقط لهذا الشهر.", ['type' => 'leave']);
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
            'تم رفض طلب إجازتك.' . ($note ? " السبب: {$note}" : ''), ['type' => 'leave', 'leave_id' => $leave->id]);

        return $leave->fresh(['user:id,name']);
    }

    /** Notify admins + the employee's branch manager. */
    private function notifyReviewers(User $employee, string $title, string $message): void
    {
        $managerId = $employee->shop_id ? Shop::where('id', $employee->shop_id)->value('manager_id') : null;
        if ($managerId) {
            $this->notifications->notify([$managerId], 'leave', $title, $message, ['type' => 'leave']);
        }
        $this->notifications->notifyAdmins('leave', $title, $message, ['type' => 'leave']);
    }
}
