<?php

namespace App\Modules\Hr\Services;

use App\Models\EmployeeSettlement;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Employee lifecycle for the HR module. Employees are regular `users` (same
 * auth) whose role is manager or sales, extended with HR profile fields, a
 * single primary branch (shop_id) and personal + branch commission percentages.
 * Every mutation is written to the HR audit log.
 */
class EmployeeService
{
    /** HR profile columns (excluding auth/identity handled separately). */
    private const HR_FIELDS = [
        'base_salary',
        'personal_commission_percent',
        'hire_date',
        'status',
        'monthly_leave_allowance',
        'hr_notes',
    ];

    public function __construct(
        private HrAuditLogger $audit,
        private NotificationService $notifications,
        private PayrollService $payroll,
    ) {}

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name'                        => $data['name'],
                'email'                       => $data['email'],
                'password'                    => Hash::make($data['password']),
                'phone'                       => $data['phone'] ?? null,
                'role'                        => $data['role'],
                'shop_id'                     => $data['shop_id'] ?? null, // primary branch
                'status'                      => $data['status'] ?? 'active',
                'base_salary'                 => $data['base_salary'] ?? 0,
                'personal_commission_percent' => $data['personal_commission_percent'] ?? 0,
                'hire_date'                   => $data['hire_date'] ?? null,
                'monthly_leave_allowance'      => $data['monthly_leave_allowance'] ?? 2,
                'hr_notes'                    => $data['hr_notes'] ?? null,
            ]);

            $this->audit->log('employee.created', $user, null, [
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ]);

            return $user->load('primaryBranch');
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $before = $user->only(array_merge(['name', 'email', 'phone', 'role', 'shop_id'], self::HR_FIELDS));

            $payload = [];
            foreach (array_merge(['name', 'email', 'phone', 'role', 'shop_id'], self::HR_FIELDS) as $f) {
                if (array_key_exists($f, $data)) {
                    $payload[$f] = $data[$f];
                }
            }
            if (! empty($data['password'])) {
                $payload['password'] = Hash::make($data['password']);
            }

            $user->update($payload);

            $this->auditFieldChange($user, 'salary.changed',            $before, $user, 'base_salary');
            $this->auditFieldChange($user, 'commission.changed',        $before, $user, 'personal_commission_percent');
            $this->auditFieldChange($user, 'role.changed',              $before, $user, 'role');
            $this->auditFieldChange($user, 'primary_branch.changed',    $before, $user, 'shop_id');
            $this->auditFieldChange($user, 'status.changed',            $before, $user, 'status');

            if (array_key_exists('base_salary', $before) && (string) $before['base_salary'] !== (string) $user->base_salary) {
                $this->notifications->notify([$user->id], 'employee', 'تم تعديل راتبك الأساسي',
                    'تم تحديث راتبك الأساسي — راجع صفحة ملفّك الوظيفي.', ['type' => 'employee', 'route' => '/dashboard/my-profile']);
            }
            if (array_key_exists('personal_commission_percent', $before) && (string) $before['personal_commission_percent'] !== (string) $user->personal_commission_percent) {
                $this->notifications->notify([$user->id], 'employee', 'تم تعديل نسبة عمولتك',
                    'تم تحديث نسبة عمولتك الشخصية — راجع صفحة ملفّك الوظيفي.', ['type' => 'employee', 'route' => '/dashboard/my-profile']);
            }
            if (array_key_exists('shop_id', $before) && (string) $before['shop_id'] !== (string) $user->shop_id) {
                $this->notifications->notify([$user->id], 'employee', 'تم تغيير فرعك الأساسي',
                    'تم تغيير الفرع الأساسي الخاص بك — راجع صفحة ملفّك الوظيفي.', ['type' => 'employee', 'route' => '/dashboard/my-profile']);
            }

            return $user->load('primaryBranch');
        });
    }

    public function toggleStatus(User $user): User
    {
        $old = $user->status;
        $activating = $old === 'inactive';

        // An employee (manager/sales) still on the job (employment_status =
        // working) can never have their account deactivated — end their
        // employment first (see endEmployment() below), the only thing
        // allowed to move them out of 'working'. Reactivating is never
        // blocked by this check. Admin accounts have no employment lifecycle
        // at all (this service is also reused by UsersManagmentController for
        // admin accounts) so they're exempt — employment_status is otherwise
        // meaningless for them and must never block their own deactivation.
        if (! $activating && $user->role !== 'admin' && $user->employment_status === 'working') {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن تعطيل الحساب لأن الموظف ما زال على رأس العمل. قم أولاً بإنهاء الخدمة أو تسجيل الاستقالة.',
            ]);
        }

        $user->update(['status' => $activating ? 'active' : 'inactive']);
        $this->audit->log('status.changed', $user, ['status' => $old], ['status' => $user->status]);

        return $user;
    }

    /**
     * Validate that $user is eligible to leave, and normalize the leaving
     * date/type — shared by previewSettlement() (read-only) and
     * endEmployment() (persists) so both can never disagree.
     */
    private function assertCanEndEmployment(User $user, string $type): void
    {
        if (! in_array($type, ['resigned', 'terminated'], true)) {
            throw ValidationException::withMessages(['type' => 'نوع إنهاء الخدمة غير صالح.']);
        }
        if ($user->employment_status !== 'working') {
            throw ValidationException::withMessages(['employment_status' => 'تم بالفعل إنهاء خدمة هذا الموظف.']);
        }
    }

    /**
     * Final Settlement preview — read-only, persists nothing. Lets the admin
     * review the full breakdown (see PayrollService::settlementPreview) before
     * confirming via endEmployment() below, which computes the exact same
     * numbers the exact same way.
     */
    public function previewSettlement(User $user, string $type, string $leavingDate): array
    {
        $this->assertCanEndEmployment($user, $type);

        return $this->payroll->settlementPreview($user, Carbon::parse($leavingDate));
    }

    /**
     * End an employee's employment (resignation or termination): freezes
     * employment_status, saves the leaving date, and persists a permanent
     * EmployeeSettlement snapshot from the Final Settlement computed by
     * PayrollService::settlementPreview() — the employee's salary is prorated
     * to the leaving date (Base Salary / Days in Month × Worked Days);
     * commission, branch bonus, bonuses, attendance deductions and penalties
     * keep running through the EXACT SAME logic PayrollService::generate()
     * already uses for a normal month (see PayrollService::computeComponents(),
     * shared by both — never duplicated). Outstanding salary-advance balance
     * is settled in full. Never overwrites a prior settlement — an employee
     * may accumulate several across separate employment spells.
     * Does not touch users.status — that is a separate, explicit step the
     * admin takes afterwards (see UsersManagmentController/EmployeeController
     * toggleStatus, now unblocked once employment_status leaves 'working').
     */
    public function endEmployment(User $user, string $type, string $leavingDate): array
    {
        $this->assertCanEndEmployment($user, $type);

        return DB::transaction(function () use ($user, $type, $leavingDate) {
            $leaving    = Carbon::parse($leavingDate);
            $breakdown  = $this->payroll->settlementPreview($user, $leaving);
            $settlement = $breakdown['final_settlement'];

            $before = ['employment_status' => $user->employment_status];
            $user->update([
                'employment_status'       => $type,
                'leaving_date'            => $leaving->toDateString(),
                'final_settlement_amount' => $settlement,
            ]);

            $this->audit->log('employee.' . $type, $user, $before, [
                'employment_status'       => $type,
                'leaving_date'            => $user->leaving_date->toDateString(),
                'final_settlement_amount' => $settlement,
            ]);

            $doc = EmployeeSettlement::create([
                'settlement_number' => 'ST-000000', // placeholder, replaced below once the row has an id
                'employee_id'       => $user->id,
                'prepared_by'       => auth()->id(),
                'employment_status' => $type,
                'leaving_date'      => $leaving->toDateString(),
                'worked_days'       => $breakdown['worked_days'],
                'daily_rate'        => $breakdown['daily_rate'],
                'basic_salary'      => $breakdown['basic_salary'],
                'salary_earned'     => $breakdown['salary_earned'],
                'sales_commission'  => $breakdown['personal_commission'],
                'branch_profit'     => $breakdown['branch_bonus'],
                'bonuses'           => $breakdown['bonus_total'],
                'deductions'        => $breakdown['total_deductions'],
                'salary_advances'   => $breakdown['advance_balance'],
                'final_settlement'  => $settlement,
            ]);
            $doc->update(['settlement_number' => 'ST-' . str_pad((string) $doc->id, 6, '0', STR_PAD_LEFT)]);

            $this->audit->log('settlement.created', $user, null, [
                'settlement_id'     => $doc->id,
                'settlement_number' => $doc->settlement_number,
                'final_settlement'  => $settlement,
            ]);

            return array_merge($breakdown, ['employee' => $user, 'settlement' => $doc]);
        });
    }

    private function auditFieldChange(User $user, string $action, array $before, User $after, string $field): void
    {
        if (! array_key_exists($field, $before)) {
            return;
        }
        if ((string) $before[$field] !== (string) $after->{$field}) {
            $this->audit->log($action, $user, [$field => $before[$field]], [$field => $after->{$field}]);
        }
    }
}
