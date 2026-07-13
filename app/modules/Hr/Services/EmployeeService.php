<?php

namespace App\Modules\Hr\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

    public function __construct(private HrAuditLogger $audit) {}

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

            return $user->load('primaryBranch');
        });
    }

    public function toggleStatus(User $user): User
    {
        $old = $user->status;
        $user->update(['status' => $old === 'active' ? 'inactive' : 'active']);
        $this->audit->log('status.changed', $user, ['status' => $old], ['status' => $user->status]);

        return $user;
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
