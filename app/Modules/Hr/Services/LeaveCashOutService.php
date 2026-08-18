<?php

namespace App\Modules\Hr\Services;

use App\Models\LeaveCashOut;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Leave Encashment (Admin cashes out part of an employee's accumulated,
 * carry-over leave balance). Uses the SAME daily-rate policy PayrollService
 * already uses (base_salary / real days-in-month) — no separate rate
 * engine. Once created, a LeaveCashOut row is immutable and permanently
 * reduces LeaveService::balance()'s cumulative remaining figure, so the
 * same days can never be cashed out twice.
 */
class LeaveCashOutService
{
    public function __construct(
        private LeaveService $leaves,
        private HrAuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    public function cashOut(User $employee, float $days, ?string $note = null, ?string $date = null): LeaveCashOut
    {
        if ($days <= 0) {
            throw ValidationException::withMessages(['days' => 'عدد الأيام يجب أن يكون أكبر من صفر.']);
        }

        $cashOutDate = $date ? Carbon::parse($date) : now();
        $available   = (float) $this->leaves->balance($employee)['remaining'];

        if ($days > $available) {
            throw ValidationException::withMessages([
                'days' => "رصيد الإجازات المتاح ({$available} يوم) أقل من عدد الأيام المطلوب تحويلها ({$days} يوم).",
            ]);
        }

        $daysInMonth = $cashOutDate->daysInMonth;
        $dailyRate   = $daysInMonth > 0 ? round((float) $employee->base_salary / $daysInMonth, 2) : 0.0;
        $amount      = round($dailyRate * $days, 2);

        $cashOut = LeaveCashOut::create([
            'user_id'    => $employee->id,
            'date'       => $cashOutDate->toDateString(),
            'days'       => $days,
            'daily_rate' => $dailyRate,
            'amount'     => $amount,
            'note'       => $note,
            'created_by' => auth()->id(),
        ]);

        $this->audit->log('leave_cash_out.created', $cashOut, null,
            $cashOut->only(['user_id', 'date', 'days', 'daily_rate', 'amount']));

        $this->notifications->notify([$employee->id], 'leave', 'تحويل إجازة إلى نقد',
            "تم تحويل {$days} يوم من رصيد إجازتك إلى مبلغ " . number_format($amount, 2) . ' ج.م',
            ['type' => 'leave_cash_out', 'route' => '/dashboard/my-leave']);

        return $cashOut->load('user:id,name');
    }

    /** Cash-outs for an employee within [from, to] (for payroll generation). */
    public function forPeriod(int $userId, Carbon $from, Carbon $to)
    {
        return LeaveCashOut::where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
    }
}
