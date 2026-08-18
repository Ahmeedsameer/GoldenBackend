<?php

namespace App\Modules\Hr\Services;

use App\Models\EmployeeTransfer;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Resolves an employee's ACTIVE branch — the single branch they belong to on a
 * given date. Default is the primary branch (users.shop_id); an effective
 * transfer (scheduled/active/completed) whose [start,end] covers the date
 * overrides it with the temporary branch.
 *
 * This is the source of truth for attendance, invoice branch, payroll branch
 * bonus and dashboards.
 */
class ActiveBranchService
{
    /** The employee's active branch id on $date (null if no primary + no transfer). */
    public function activeBranchId(User $employee, ?Carbon $date = null): ?int
    {
        $date ??= Carbon::today();

        $transfer = EmployeeTransfer::query()
            ->where('user_id', $employee->id)
            ->whereIn('status', EmployeeTransfer::EFFECTIVE_STATUSES)
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->orderByDesc('start_date')
            ->first();

        return $transfer ? (int) $transfer->temporary_branch_id : ($employee->shop_id ? (int) $employee->shop_id : null);
    }

    /**
     * Split [from, to] into contiguous segments, each tagged with the active
     * branch for that sub-period. Used by payroll to compute the branch bonus
     * per active-branch period.
     *
     * @return array<int, array{shop_id: ?int, from: Carbon, to: Carbon}>
     */
    public function activeBranchSegments(User $employee, Carbon $from, Carbon $to): array
    {
        $segments = [];
        $cursor   = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $branchId = $this->activeBranchId($employee, $cursor);

            // Extend this segment day-by-day while the active branch is unchanged.
            $segStart = $cursor->copy();
            $next     = $cursor->copy()->addDay();
            while ($next->lessThanOrEqualTo($to) && $this->activeBranchId($employee, $next) === $branchId) {
                $next->addDay();
            }
            $segEnd = $next->copy()->subDay();

            $segments[] = ['shop_id' => $branchId, 'from' => $segStart, 'to' => $segEnd];
            $cursor = $next;
        }

        return $segments;
    }
}
