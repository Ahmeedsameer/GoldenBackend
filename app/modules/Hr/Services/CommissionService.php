<?php

namespace App\Modules\Hr\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Commission math — single source of truth for personal and branch commissions.
 * The INVOICE is always authoritative:
 *
 *   - invoices.seller_id → who earns the PERSONAL commission
 *   - invoices.shop_id   → which branch a sale belongs to (frozen at creation,
 *                          = the employee's ACTIVE branch at that moment)
 *
 * Only APPROVED invoices whose `date` falls in the period are counted.
 *
 * Branch bonus honours temporary transfers: the payroll month is split into
 * active-branch segments (primary vs temporary branch), and for each segment we
 * take that branch's total approved sales × the employee's branch commission %.
 */
class CommissionService
{
    public const APPROVED = 'approved';

    public function __construct(private BranchBonusService $branchBonus) {}

    /** Total approved sales personally created by the employee within [from, to]. */
    public function personalSalesTotal(int $userId, Carbon $from, Carbon $to): float
    {
        return (float) Invoice::query()
            ->where('seller_id', $userId)
            ->where('status', self::APPROVED)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->sum('total_amount');
    }

    /** Personal commission = personal approved sales × personal commission %. */
    public function personalCommission(User $employee, Carbon $from, Carbon $to): array
    {
        $sales   = $this->personalSalesTotal($employee->id, $from, $to);
        $percent = (float) $employee->personal_commission_percent;

        return [
            'sales_total' => round($sales, 2),
            'percent'     => $percent,
            'amount'      => round($sales * $percent / 100, 2),
        ];
    }

    /**
     * Branch bonus — the employee's equal share of each branch's bonus pool
     * across their active-branch periods (delegated to BranchBonusService).
     *
     * @return array{ total: float, segments: array<int, array<string, mixed>> }
     */
    public function branchCommission(User $employee, Carbon $from, Carbon $to): array
    {
        return $this->branchBonus->forEmployee($employee, $from, $to);
    }
}
