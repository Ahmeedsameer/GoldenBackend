<?php

namespace App\Modules\Hr\Services;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Daily attendance, scoped to each employee's ACTIVE branch.
 *
 * The roster for a branch on a date is exactly the set of employees whose active
 * branch resolves to that branch that day (primary employees minus those
 * transferred out, plus those transferred in). Default status is 'absent';
 * one click flips it to present / late / absent / half_day (idempotent upsert).
 */
class AttendanceService
{
    public function __construct(
        private BranchBonusService $branch,   // reuses activeEmployeeIds()
        private ActiveBranchService $activeBranch,
        private HrAuditLogger $audit,
    ) {}

    /**
     * The attendance roster for a branch on a date: every actively-assigned
     * employee with their marked status (default 'absent').
     *
     * @return array<int, array{user_id:int, name:string, role:string, status:string, note:?string}>
     */
    public function roster(int $shopId, Carbon $date): array
    {
        $ids = $this->branch->activeEmployeeIds($shopId, $date);
        if (! $ids) {
            return [];
        }

        $employees = User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'role']);

        $records = Attendance::where('shop_id', $shopId)
            ->whereDate('date', $date->toDateString())
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');

        return $employees->map(function (User $e) use ($records) {
            $rec = $records->get($e->id);
            return [
                'user_id' => $e->id,
                'name'    => $e->name,
                'role'    => $e->role,
                'status'  => $rec->status ?? Attendance::ABSENT,
                'note'    => $rec->note ?? null,
            ];
        })->all();
    }

    /**
     * Mark (upsert) one employee's attendance for a day. The attendance row is
     * stamped with the employee's ACTIVE branch on that date, so history stays
     * correct across transfers.
     */
    public function mark(int $userId, Carbon $date, string $status, ?string $note = null): Attendance
    {
        $shopId = $this->activeBranch->activeBranchId(User::find($userId), $date);

        $existing = Attendance::where('user_id', $userId)->whereDate('date', $date->toDateString())->first();
        $old      = $existing?->status;

        $attendance = Attendance::updateOrCreate(
            ['user_id' => $userId, 'date' => $date->toDateString()],
            ['status' => $status, 'note' => $note, 'shop_id' => $shopId, 'marked_by' => auth()->id()],
        );

        if ($old !== $status) {
            $this->audit->log('attendance.marked', $attendance,
                ['status' => $old],
                ['status' => $status, 'date' => $date->toDateString(), 'user_id' => $userId],
            );
        }

        return $attendance;
    }

    /**
     * Attendance counts for an employee over [from, to] (for payroll + dashboards).
     *
     * @return array{present:int, late:int, absent:int, half_day:int, leave:int}
     */
    public function summary(int $userId, Carbon $from, Carbon $to): array
    {
        $rows = Attendance::where('user_id', $userId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('status')
            ->map->count();

        return [
            'present'  => (int) ($rows[Attendance::PRESENT] ?? 0),
            'late'     => (int) ($rows[Attendance::LATE] ?? 0),
            'absent'   => (int) ($rows[Attendance::ABSENT] ?? 0),
            'half_day' => (int) ($rows[Attendance::HALF_DAY] ?? 0),
            'leave'    => (int) ($rows[Attendance::LEAVE] ?? 0),
        ];
    }

    /**
     * Mark a RANGE of days as 'leave' (approved-leave automation). Skips days
     * covered by an active transfer (the transfer's own branch stays
     * authoritative) — mirrors ScheduleService's skip behaviour so the two
     * stay consistent for the same employee/date.
     *
     * @return int[] dates actually marked
     */
    public function markLeaveRange(int $userId, Carbon $from, Carbon $to): array
    {
        $marked = [];
        $employee = User::find($userId);
        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $shopId = $this->activeBranch->activeBranchId($employee, $cursor);
            Attendance::updateOrCreate(
                ['user_id' => $userId, 'date' => $cursor->toDateString()],
                ['status' => Attendance::LEAVE, 'shop_id' => $shopId, 'marked_by' => auth()->id()],
            );
            $marked[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $marked;
    }

    /** Remove 'leave' attendance rows for a range (early leave termination). */
    public function clearLeaveRange(int $userId, Carbon $from, Carbon $to): int
    {
        return Attendance::where('user_id', $userId)
            ->where('status', Attendance::LEAVE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->delete();
    }
}
