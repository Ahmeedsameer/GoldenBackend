<?php

namespace App\Modules\Hr\Services;

use App\Models\EmployeeTransfer;
use App\Models\Shop;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee temporary-transfer lifecycle:
 *   draft → (approve) → scheduled → (start_date) → active → (end_date) → completed
 *   cancel before activation → cancelled
 *
 * Every transition is audited and notifies the employee, the destination branch
 * manager and the admins. The employee's ACTIVE branch is derived from these
 * rows by ActiveBranchService — invoices/attendance/payroll follow automatically.
 */
class TransferService
{
    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
    ) {}

    public function create(array $data): EmployeeTransfer
    {
        return DB::transaction(function () use ($data) {
            $employee = User::findOrFail($data['user_id']);

            $transfer = EmployeeTransfer::create([
                'user_id'             => $employee->id,
                'primary_branch_id'   => $employee->shop_id,
                'temporary_branch_id' => $data['temporary_branch_id'],
                'start_date'          => $data['start_date'],
                'end_date'            => $data['end_date'],
                'reason'              => $data['reason'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'status'              => EmployeeTransfer::DRAFT,
                'requested_by'        => auth()->id(),
            ]);

            $this->audit->log('transfer.created', $transfer, null, $transfer->only([
                'user_id', 'temporary_branch_id', 'start_date', 'end_date', 'status',
            ]));
            $this->notifyStatus($transfer, 'created');

            return $transfer->load(['user:id,name', 'temporaryBranch:id,name', 'primaryBranch:id,name']);
        });
    }

    public function update(EmployeeTransfer $transfer, array $data): EmployeeTransfer
    {
        if ($transfer->status !== EmployeeTransfer::DRAFT) {
            throw ValidationException::withMessages(['status' => 'لا يمكن تعديل النقل إلا في حالة المسودّة.']);
        }

        $before = $transfer->only(['temporary_branch_id', 'start_date', 'end_date', 'reason', 'notes']);
        $transfer->update([
            'temporary_branch_id' => $data['temporary_branch_id'] ?? $transfer->temporary_branch_id,
            'start_date'          => $data['start_date'] ?? $transfer->start_date,
            'end_date'            => $data['end_date'] ?? $transfer->end_date,
            'reason'              => $data['reason'] ?? $transfer->reason,
            'notes'               => $data['notes'] ?? $transfer->notes,
        ]);

        $this->audit->log('transfer.edited', $transfer, $before, $transfer->only(array_keys($before)));

        return $transfer->fresh(['user:id,name', 'temporaryBranch:id,name']);
    }

    /** Approve a draft → scheduled (or straight to active/completed by dates). */
    public function approve(EmployeeTransfer $transfer): EmployeeTransfer
    {
        if ($transfer->status !== EmployeeTransfer::DRAFT) {
            throw ValidationException::withMessages(['status' => 'لا يمكن اعتماد نقل إلا من حالة المسودّة.']);
        }

        $this->assertNoOverlap($transfer);

        $old = $transfer->status;
        $transfer->update([
            'status'        => $this->statusForToday($transfer),
            'approved_by'   => auth()->id(),
            'approval_date' => now(),
        ]);

        $this->audit->log('transfer.approved', $transfer, ['status' => $old], ['status' => $transfer->status]);
        $this->notifyStatus($transfer, 'approved');

        // If approving already activated it (start date already reached), announce it.
        if ($transfer->status === EmployeeTransfer::ACTIVE) {
            $this->notifyStatus($transfer, 'activated');
        }

        return $transfer->fresh(['user:id,name', 'temporaryBranch:id,name']);
    }

    /** Cancel a transfer before it activates. */
    public function cancel(EmployeeTransfer $transfer): EmployeeTransfer
    {
        if (! in_array($transfer->status, [EmployeeTransfer::DRAFT, EmployeeTransfer::SCHEDULED], true)) {
            throw ValidationException::withMessages(['status' => 'لا يمكن إلغاء النقل بعد تفعيله.']);
        }

        $old = $transfer->status;
        $transfer->update(['status' => EmployeeTransfer::CANCELLED]);

        $this->audit->log('transfer.cancelled', $transfer, ['status' => $old], ['status' => EmployeeTransfer::CANCELLED]);
        $this->notifyStatus($transfer, 'cancelled');

        return $transfer->fresh(['user:id,name', 'temporaryBranch:id,name']);
    }

    /**
     * Automatic date-driven transitions — run daily by the hr:process-transfers
     * command. scheduled → active (on/after start), active/scheduled → completed
     * (after end). Idempotent.
     *
     * @return array{activated: int, completed: int}
     */
    public function processDue(): array
    {
        $today = Carbon::today();
        $activated = 0;
        $completed = 0;

        // scheduled → active
        EmployeeTransfer::where('status', EmployeeTransfer::SCHEDULED)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->get()
            ->each(function (EmployeeTransfer $t) use (&$activated) {
                $t->update(['status' => EmployeeTransfer::ACTIVE]);
                $this->audit->log('transfer.activated', $t, ['status' => EmployeeTransfer::SCHEDULED], ['status' => EmployeeTransfer::ACTIVE]);
                $this->notifyStatus($t, 'activated');
                $activated++;
            });

        // active/scheduled whose window has passed → completed
        EmployeeTransfer::whereIn('status', [EmployeeTransfer::ACTIVE, EmployeeTransfer::SCHEDULED])
            ->whereDate('end_date', '<', $today)
            ->get()
            ->each(function (EmployeeTransfer $t) use (&$completed) {
                $old = $t->status;
                $t->update(['status' => EmployeeTransfer::COMPLETED]);
                $this->audit->log('transfer.completed', $t, ['status' => $old], ['status' => EmployeeTransfer::COMPLETED]);
                $this->notifyStatus($t, 'completed');
                $completed++;
            });

        return ['activated' => $activated, 'completed' => $completed];
    }

    /** Status a transfer should hold given today's date at approval time. */
    private function statusForToday(EmployeeTransfer $transfer): string
    {
        $today = Carbon::today();
        if ($today->lt($transfer->start_date)) {
            return EmployeeTransfer::SCHEDULED;
        }
        if ($today->betweenIncluded($transfer->start_date, $transfer->end_date)) {
            return EmployeeTransfer::ACTIVE;
        }
        return EmployeeTransfer::COMPLETED;
    }

    /** Prevent two effective transfers overlapping for the same employee. */
    private function assertNoOverlap(EmployeeTransfer $transfer): void
    {
        $overlap = EmployeeTransfer::query()
            ->where('user_id', $transfer->user_id)
            ->where('id', '!=', $transfer->id)
            ->whereIn('status', EmployeeTransfer::EFFECTIVE_STATUSES)
            ->whereDate('start_date', '<=', $transfer->end_date)
            ->whereDate('end_date', '>=', $transfer->start_date)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'dates' => 'يوجد نقل آخر معتمد للموظف يتداخل مع هذه الفترة.',
            ]);
        }
    }

    /** Notify employee + destination branch manager + admins about a status change. */
    private function notifyStatus(EmployeeTransfer $transfer, string $event): void
    {
        $labels = [
            'created'   => 'تم إنشاء طلب نقل',
            'approved'  => 'تم اعتماد نقل',
            'activated' => 'تم تفعيل نقل',
            'completed' => 'انتهى نقل وعاد الموظف لفرعه',
            'cancelled' => 'تم إلغاء نقل',
        ];
        $title = $labels[$event] ?? 'تحديث نقل موظف';

        $branch  = Shop::find($transfer->temporary_branch_id, ['id', 'name', 'manager_id']);
        $empName = optional($transfer->user)->name ?? User::find($transfer->user_id)?->name;
        $message = "{$empName} — الفرع المؤقت: " . ($branch->name ?? '') .
            " ({$transfer->start_date->toDateString()} → {$transfer->end_date->toDateString()})";

        $recipients = array_filter([
            $transfer->user_id,          // the employee
            $branch?->manager_id,        // destination branch manager
        ]);

        $data = ['type' => 'employee_transfer', 'transfer_id' => $transfer->id, 'event' => $event];

        if ($recipients) {
            $this->notifications->notify($recipients, 'employee_transfer', $title, $message, $data);
        }
        $this->notifications->notifyAdmins('employee_transfer', $title, $message, $data);
    }
}
