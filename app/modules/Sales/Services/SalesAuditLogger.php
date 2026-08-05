<?php

namespace App\Modules\Sales\Services;

use App\Models\SalesAuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Central Sales audit trail — the Sales-module counterpart to
 * App\Modules\Hr\Services\HrAuditLogger, same shape and usage style, kept
 * fully independent (own table, own model) so the two modules never share
 * state. Every meaningful Customer/Invoice action calls this so the Customer
 * Activity Timeline has a single, consistent source of events.
 *
 * New action types (e.g. 'product_returned', 'loyalty_points_earned',
 * 'credit_payment_received', 'customer_merged', 'customer_archived') need no
 * schema change — just call log() with a new $action string.
 */
class SalesAuditLogger
{
    /**
     * @param  string     $action     e.g. 'customer_created', 'invoice_cancelled'
     * @param  Model|null $subject    the affected entity (customer, invoice, note, tag…)
     * @param  array|null $old        previous values (only changed keys)
     * @param  array|null $new        new values (only changed keys)
     * @param  int|null   $customerId the customer this event belongs to, for fast timeline lookups
     */
    public function log(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?int $customerId = null): SalesAuditLog
    {
        return SalesAuditLog::create([
            'actor_id'     => auth()->id(),
            'action'       => $action,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id'   => $subject?->getKey(),
            'customer_id'  => $customerId,
            'old_values'   => $old,
            'new_values'   => $new,
            'ip_address'   => request()->ip(),
            'created_at'   => now(),
        ]);
    }
}
