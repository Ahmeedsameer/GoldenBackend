<?php

namespace App\Modules\Hr\Services;

use App\Models\Bonus;
use App\Models\Penalty;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;

/**
 * Manual Bonuses & Penalties (Admin only). Both are read live by
 * PayrollService at generation time (never duplicated into payroll_lines
 * until a payroll actually runs), exactly like commission/branch-bonus.
 */
class BonusPenaltyService
{
    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    public function createBonus(User $employee, array $data): Bonus
    {
        $bonus = Bonus::create([
            'user_id'    => $employee->id,
            'amount'     => $data['amount'],
            'reason'     => $data['reason'],
            'date'       => $data['date'],
            'created_by' => auth()->id(),
            'notes'      => $data['notes'] ?? null,
            'status'     => Bonus::ACTIVE,
        ]);

        $this->audit->log('bonus.created', $bonus, null, $bonus->only(['user_id', 'amount', 'reason', 'date']));
        $this->notifications->notify([$employee->id], 'bonus', 'تم منحك مكافأة',
            "مكافأة بقيمة " . number_format((float) $bonus->amount, 2) . " ج.م — {$bonus->reason}",
            ['type' => 'bonus', 'bonus_id' => $bonus->id, 'route' => '/dashboard/my-profile']);

        return $bonus;
    }

    public function updateBonus(Bonus $bonus, array $data): Bonus
    {
        $before = $bonus->only(['amount', 'reason', 'date', 'notes', 'status']);
        $bonus->update(array_intersect_key($data, array_flip(['amount', 'reason', 'date', 'notes', 'status'])));
        $this->audit->log('bonus.updated', $bonus, $before, $bonus->only(['amount', 'reason', 'date', 'notes', 'status']));

        return $bonus;
    }

    public function deleteBonus(Bonus $bonus): void
    {
        $this->audit->log('bonus.deleted', $bonus, $bonus->only(['user_id', 'amount', 'reason', 'date']), null);
        $bonus->delete();
    }

    public function createPenalty(User $employee, array $data): Penalty
    {
        $penalty = Penalty::create([
            'user_id'    => $employee->id,
            'amount'     => $data['amount'],
            'reason'     => $data['reason'],
            'date'       => $data['date'],
            'created_by' => auth()->id(),
            'notes'      => $data['notes'] ?? null,
            'status'     => Penalty::ACTIVE,
        ]);

        $this->audit->log('penalty.created', $penalty, null, $penalty->only(['user_id', 'amount', 'reason', 'date']));
        $this->notifications->notify([$employee->id], 'penalty', 'تم تسجيل خصم عليك',
            "خصم بقيمة " . number_format((float) $penalty->amount, 2) . " ج.م — {$penalty->reason}",
            ['type' => 'penalty', 'penalty_id' => $penalty->id, 'route' => '/dashboard/my-profile']);

        return $penalty;
    }

    public function updatePenalty(Penalty $penalty, array $data): Penalty
    {
        $before = $penalty->only(['amount', 'reason', 'date', 'notes', 'status']);
        $penalty->update(array_intersect_key($data, array_flip(['amount', 'reason', 'date', 'notes', 'status'])));
        $this->audit->log('penalty.updated', $penalty, $before, $penalty->only(['amount', 'reason', 'date', 'notes', 'status']));

        return $penalty;
    }

    public function deletePenalty(Penalty $penalty): void
    {
        $this->audit->log('penalty.deleted', $penalty, $penalty->only(['user_id', 'amount', 'reason', 'date']), null);
        $penalty->delete();
    }

    /** Active bonuses for an employee within [from, to] (for payroll generation). */
    public function bonusesFor(int $userId, Carbon $from, Carbon $to)
    {
        return Bonus::where('user_id', $userId)->where('status', Bonus::ACTIVE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
    }

    /** Active penalties for an employee within [from, to] (for payroll generation). */
    public function penaltiesFor(int $userId, Carbon $from, Carbon $to)
    {
        return Penalty::where('user_id', $userId)->where('status', Penalty::ACTIVE)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();
    }
}
