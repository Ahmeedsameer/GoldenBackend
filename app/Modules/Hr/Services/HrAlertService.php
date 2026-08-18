<?php

namespace App\Modules\Hr\Services;

use App\Models\Attendance;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;

/**
 * Scheduled HR alerts that need a periodic check (not tied to a single request):
 *   - repeated absences → the employee's branch manager + admins
 *   - a branch whose attendance wasn't completed for the day → admins
 */
class HrAlertService
{
    public const ABSENCE_WINDOW_DAYS = 7;
    public const ABSENCE_THRESHOLD   = 3;

    public function __construct(
        private NotificationService $notifications,
        private BranchBonusService $branch, // activeEmployeeIds()
    ) {}

    /**
     * Employees with >= threshold absences in the trailing window get flagged to
     * their branch manager and the admins. De-duped per employee per run.
     *
     * @return int number of employees flagged
     */
    public function repeatedAbsences(?Carbon $asOf = null): int
    {
        $asOf  = $asOf ?? Carbon::today();
        $from  = $asOf->copy()->subDays(self::ABSENCE_WINDOW_DAYS - 1);

        $counts = Attendance::where('status', Attendance::ABSENT)
            ->whereBetween('date', [$from->toDateString(), $asOf->toDateString()])
            ->selectRaw('user_id, COUNT(*) as c')
            ->groupBy('user_id')
            ->having('c', '>=', self::ABSENCE_THRESHOLD)
            ->pluck('c', 'user_id');

        $flagged = 0;
        foreach ($counts as $userId => $c) {
            $employee = User::find($userId);
            if (! $employee) {
                continue;
            }
            $managerId = $employee->shop_id ? Shop::where('id', $employee->shop_id)->value('manager_id') : null;
            $msg = "{$employee->name} تغيّب {$c} أيام خلال آخر " . self::ABSENCE_WINDOW_DAYS . " أيام.";

            $recipients = array_filter([$managerId]);
            if ($recipients) {
                $this->notifications->notify($recipients, 'hr_absence', 'غياب متكرر', $msg, ['type' => 'hr_absence', 'user_id' => $userId]);
            }
            $this->notifications->notifyAdmins('hr_absence', 'غياب متكرر', $msg, ['type' => 'hr_absence', 'user_id' => $userId]);
            $flagged++;
        }

        return $flagged;
    }

    /**
     * Branches where attendance for $date is incomplete — at least one actively
     * assigned employee has no attendance record that day. Admins are notified.
     *
     * @return int number of branches flagged
     */
    public function incompleteBranchAttendance(?Carbon $date = null): int
    {
        $date    = $date ?? Carbon::today();
        $flagged = 0;

        foreach (Shop::get(['id', 'name']) as $shop) {
            $activeIds = $this->branch->activeEmployeeIds($shop->id, $date);
            if (! $activeIds) {
                continue;
            }
            $markedIds = Attendance::where('shop_id', $shop->id)
                ->whereDate('date', $date->toDateString())
                ->whereIn('user_id', $activeIds)
                ->pluck('user_id')
                ->all();

            $missing = array_diff($activeIds, $markedIds);
            if ($missing) {
                $this->notifications->notifyAdmins('hr_attendance_incomplete', 'حضور غير مكتمل',
                    "لم يُستكمل تسجيل الحضور في فرع «{$shop->name}» ليوم {$date->toDateString()} (" . count($missing) . " موظف).",
                    ['type' => 'hr_attendance_incomplete', 'shop_id' => $shop->id, 'date' => $date->toDateString()]);
                $flagged++;
            }
        }

        return $flagged;
    }
}
