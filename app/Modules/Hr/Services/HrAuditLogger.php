<?php

namespace App\Modules\Hr\Services;

use App\Models\HrAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Central HR audit trail. Every meaningful HR action calls this so we have a
 * single, consistent record (actor, action, subject, old→new, IP, timestamp).
 */
class HrAuditLogger
{
    /**
     * @param  string       $action     e.g. 'employee.created', 'salary.changed'
     * @param  Model|null   $subject     the affected entity (employee, payroll…)
     * @param  array|null   $old         previous values (only changed keys)
     * @param  array|null   $new         new values (only changed keys)
     */
    public function log(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null): HrAuditLog
    {
        return HrAuditLog::create([
            'actor_id'     => auth()->id(),
            'action'       => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id'   => $subject?->getKey(),
            'old_values'   => $old,
            'new_values'   => $new,
            'ip_address'   => request()->ip(),
            'created_at'   => now(),
        ]);
    }
}
