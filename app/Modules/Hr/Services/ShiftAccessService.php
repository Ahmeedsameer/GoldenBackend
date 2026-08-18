<?php

namespace App\Modules\Hr\Services;

use App\Models\OvertimeRequest;
use App\Models\ScheduleEntry;
use Illuminate\Support\Carbon;

/**
 * THE single "can this user sell right now" check, mirroring
 * LeaveRequest::leaveMessageFor() exactly. A sales/manager employee may only
 * create sales while inside a published WORK shift for today, OR inside an
 * approved Overtime window for today. Outside both, they are view-only.
 *
 * Admin is never subject to this — callers must skip it for role=admin
 * (see SalesService::createInvoice()), per business rule "Admin is not
 * restricted by employee shift rules."
 */
class ShiftAccessService
{
    public const OFF_SHIFT = 'off_shift';
    public const WORKING   = 'working';
    public const OVERTIME  = 'overtime';

    public function __construct(private OvertimeService $overtime) {}

    /** Today's published WORK schedule entry for the user, if any. */
    private function todaysWorkShift(int $userId): ?ScheduleEntry
    {
        return ScheduleEntry::where('user_id', $userId)
            ->whereDate('date', today())
            ->where('type', ScheduleEntry::WORK)
            ->where('is_published', true)
            ->first();
    }

    private function withinWorkShiftNow(?ScheduleEntry $shift): bool
    {
        if (! $shift || ! $shift->start_time || ! $shift->end_time) {
            return false;
        }

        $now = now()->format('H:i:s');

        return $now >= $shift->start_time && $now <= $shift->end_time;
    }

    /**
     * OFF_SHIFT | WORKING | OVERTIME — backend-determined, never a frontend
     * assumption. Used both by the sale-blocking check and the self-service
     * shift-status endpoint so they can never drift apart.
     */
    public function statusFor(int $userId): array
    {
        $shift = $this->todaysWorkShift($userId);

        if ($this->withinWorkShiftNow($shift)) {
            return ['status' => self::WORKING, 'shift' => $shift, 'overtime' => null];
        }

        $overtime = $this->overtime->activeWindowNowFor($userId);
        if ($overtime) {
            return ['status' => self::OVERTIME, 'shift' => $shift, 'overtime' => $overtime];
        }

        return ['status' => self::OFF_SHIFT, 'shift' => $shift, 'overtime' => null];
    }

    /**
     * Returns a ready-to-show Arabic message when the user is outside both
     * their shift and any approved overtime window, or null when selling is
     * allowed right now.
     */
    public function blockMessageFor(int $userId): ?string
    {
        $result = $this->statusFor($userId);

        if ($result['status'] !== self::OFF_SHIFT) {
            return null;
        }

        if (! $result['shift']) {
            return 'لا يوجد جدول عمل مُعتمد لك اليوم — لا يمكنك إجراء عمليات بيع، يمكنك فقط عرض بياناتك.';
        }

        return "انتهى وقت دوامك المحدد اليوم ({$result['shift']->start_time} - {$result['shift']->end_time}) — لا يمكنك إجراء عمليات بيع الآن.";
    }
}
