<?php

namespace App\Modules\Hr\Services;

use App\Models\OvertimeRequest;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;

/**
 * Admin-granted Overtime. Extends an employee's selling window for the
 * granted date/time range and is read live by PayrollService at generation
 * time (never duplicated into payroll_lines until a payroll actually runs),
 * exactly like Bonuses/Penalties.
 */
class OvertimeService
{
    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    public function create(User $employee, array $data): OvertimeRequest
    {
        $start = Carbon::parse($data['start_time']);
        $end = Carbon::parse($data['end_time']);
        $hours = round($start->diffInMinutes($end, true) / 60, 2);
        $rate = (float) $data['hourly_rate'];
        $pay = round($hours * $rate, 2);

        $overtime = OvertimeRequest::create([
            'user_id'     => $employee->id,
            'date'        => $data['date'],
            'start_time'  => $data['start_time'],
            'end_time'    => $data['end_time'],
            'hourly_rate' => $rate,
            'hours'       => $hours,
            'pay'         => $pay,
            'reason'      => $data['reason'] ?? null,
            'created_by'  => auth()->id(),
            'notes'       => $data['notes'] ?? null,
            'status'      => OvertimeRequest::ACTIVE,
        ]);

        $this->audit->log('overtime.created', $overtime, null,
            $overtime->only(['user_id', 'date', 'start_time', 'end_time', 'hourly_rate', 'hours', 'pay']));

        $this->notifications->notify([$employee->id], 'overtime', 'تم منحك ساعات عمل إضافي',
            "تمت الموافقة على عمل إضافي بتاريخ {$overtime->date->toDateString()} من {$overtime->start_time} إلى {$overtime->end_time} — أجر إضافي "
                . number_format($pay, 2) . ' ج.م',
            ['type' => 'overtime', 'overtime_id' => $overtime->id, 'route' => '/dashboard/my-profile']);

        return $overtime;
    }

    public function cancel(OvertimeRequest $overtime): void
    {
        $before = $overtime->only(['status']);
        $overtime->update(['status' => OvertimeRequest::CANCELLED]);
        $this->audit->log('overtime.cancelled', $overtime, $before, ['status' => OvertimeRequest::CANCELLED]);
    }

    /** Active overtime records for an employee within [from, to] (for payroll generation). */
    public function forPeriod(int $userId, Carbon $from, Carbon $to)
    {
        return OvertimeRequest::where('user_id', $userId)->where('status', OvertimeRequest::ACTIVE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
    }

    /** Sum of overtime pay for an employee within [from, to] (for payroll generation). */
    public function totalPayFor(int $userId, Carbon $from, Carbon $to): float
    {
        return (float) $this->forPeriod($userId, $from, $to)->sum('pay');
    }

    /**
     * Today's active overtime record whose [start_time, end_time] window
     * covers the current moment, if any — used to extend selling access
     * beyond the normal shift.
     */
    public function activeWindowNowFor(int $userId): ?OvertimeRequest
    {
        $now = now()->format('H:i:s');

        return OvertimeRequest::where('user_id', $userId)
            ->where('status', OvertimeRequest::ACTIVE)
            ->whereDate('date', today())
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->first();
    }
}
