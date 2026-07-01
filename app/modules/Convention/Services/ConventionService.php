<?php

namespace App\Modules\Convention\Services;

use App\Models\Convention;
use App\Models\ConventionTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ConventionService
{
    /** Balance (EGP) at or below which the admin is alerted. */
    public const LOW_BALANCE_THRESHOLD = 100;

    public function __construct(private NotificationService $notificationService) {}

    // ── Convention CRUD ───────────────────────────────────────────────────────

    public function getAll(array $filters = [], int $perPage = 15)
    {
        $query = $this->baseConventionQuery();

        if (! empty($filters['shop_id'])) {
            $query->where('shop_id', $filters['shop_id']);
        }

        if (! empty($filters['manager_id'])) {
            $managerId = $filters['manager_id'];
            $query->whereHas('shop', fn($s) => $s->where('manager_id', $managerId));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('shop',  fn($s) => $s->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('admin', fn($a) => $a->where('name', 'like', "%{$search}%"));
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function getByShop(int $shopId): Collection
    {
        return $this->baseConventionQuery()
            ->where('shop_id', $shopId)
            ->latest()
            ->get();
    }

    /** Conventions belonging to the branch managed by the given manager. */
    public function getForManager(int $managerId): Collection
    {
        return $this->baseConventionQuery()
            ->whereHas('shop', fn($s) => $s->where('manager_id', $managerId))
            ->latest()
            ->get();
    }

    public function findOrFail(int $id): Convention
    {
        return $this->baseConventionQuery()->findOrFail($id);
    }

    /** Resolve a convention while enforcing that it belongs to the manager's branch. */
    public function findForManagerOrFail(int $id, int $managerId): Convention
    {
        $convention = $this->findOrFail($id);

        if (! $convention->shop || (int) $convention->shop->manager_id !== $managerId) {
            abort(403, 'لا تملك صلاحية الوصول لهذه العهدة');
        }

        return $convention;
    }

    public function create(array $data): Convention
    {
        $convention = Convention::create([
            'amount'   => $data['amount'],
            'admin_id' => $data['admin_id'] ?? auth()->id(),
            'shop_id'  => $data['shop_id'],
        ]);

        return $this->findOrFail($convention->id);
    }

    public function update(Convention $convention, array $data): Convention
    {
        // the convention amount can never drop below what has already been spent
        if (isset($data['amount'])) {
            $spent = $this->spent($convention->id);
            if ((float) $data['amount'] < $spent) {
                abort(422, "لا يمكن أن يكون مبلغ العهدة أقل من المصروف بالفعل ({$spent})");
            }
        }

        $convention->update(array_filter([
            'amount'   => $data['amount']   ?? null,
            'admin_id' => $data['admin_id'] ?? null,
            'shop_id'  => $data['shop_id']  ?? null,
        ], fn($v) => $v !== null));

        // raising the amount may lift the balance back above the threshold → reset the alert
        $this->syncLowBalanceNotification($convention->fresh());

        return $this->findOrFail($convention->id);
    }

    public function delete(Convention $convention): void
    {
        // related transactions are removed automatically via the FK cascade
        $convention->delete();
    }

    // ── Transactions ──────────────────────────────────────────────────────────

    public function getTransactions(Convention $convention, array $filters = [], int $perPage = 20)
    {
        $query = $convention->transactions()->with('manager:id,name,email,role', 'admin:id,name');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['manager_id'])) {
            $query->where('manager_id', $filters['manager_id']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('reason', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%")
                  ->orWhereHas('manager', fn($m) => $m->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['date_from'])) $query->whereDate('date', '>=', $filters['date_from']);
        if (! empty($filters['date_to']))   $query->whereDate('date', '<=', $filters['date_to']);

        // sorting (whitelisted)
        $sortBy  = in_array($filters['sort_by'] ?? '', ['date', 'amount', 'created_at']) ? $filters['sort_by'] : 'date';
        $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir)->orderBy('id', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * Record a withdrawal (spending) from the convention.
     *
     * @param array{amount:mixed,reason:string,notes?:string,manager_id?:int,date?:string} $data
     */
    public function withdraw(Convention $convention, array $data, ?int $managerId = null, ?int $adminId = null): ConventionTransaction
    {
        return DB::transaction(function () use ($convention, $data, $managerId, $adminId) {
            $amount = (float) $data['amount'];
            $this->guardAgainstOverspend($convention, $amount);

            $resolvedManagerId = $managerId ?? ($data['manager_id'] ?? null);

            $tx = $convention->transactions()->create([
                'type'       => ConventionTransaction::TYPE_WITHDRAW,
                'manager_id' => $resolvedManagerId,
                'admin_id'   => $adminId,
                'amount'     => $amount,
                'reason'     => $data['reason'],
                'notes'      => $data['notes'] ?? null,
                'date'       => $data['date'] ?? now()->toDateString(),
            ]);

            // The low-balance alert is ONLY triggered by a manager-performed withdrawal
            // (manager_id present, no admin acting on their behalf).
            $managerInitiated = $adminId === null && $resolvedManagerId !== null;
            $this->syncLowBalanceNotification($convention->fresh(), $managerInitiated, $resolvedManagerId);

            return $tx->load('manager:id,name,email,role', 'admin:id,name');
        });
    }

    public function updateTransaction(ConventionTransaction $transaction, array $data): ConventionTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            if (isset($data['amount'])) {
                $this->guardAgainstOverspend($transaction->convention, (float) $data['amount'], $transaction->id);
            }

            $transaction->update(array_filter([
                'manager_id' => $data['manager_id'] ?? null,
                'amount'     => $data['amount']     ?? null,
                'reason'     => $data['reason']     ?? null,
                'notes'      => $data['notes']      ?? null,
                'date'       => $data['date']       ?? null,
            ], fn($v) => $v !== null));

            $this->syncLowBalanceNotification($transaction->convention->fresh());

            return $transaction->fresh('manager:id,name,email,role', 'admin:id,name');
        });
    }

    /** Soft-delete (never physically removed). */
    public function deleteTransaction(ConventionTransaction $transaction): void
    {
        $convention = $transaction->convention;
        $transaction->delete();
        $this->syncLowBalanceNotification($convention->fresh());
    }

    // ── Balance helpers ─────────────────────────────────────────────────────────

    public function spent(int $conventionId, ?int $excludeTransactionId = null): float
    {
        return (float) ConventionTransaction::where('convention_id', $conventionId)
            ->where('type', ConventionTransaction::TYPE_WITHDRAW)
            ->when($excludeTransactionId, fn($q) => $q->where('id', '!=', $excludeTransactionId))
            ->sum('amount');
    }

    public function remaining(Convention $convention): float
    {
        return (float) $convention->amount - $this->spent($convention->id);
    }

    private function guardAgainstOverspend(Convention $convention, float $amount, ?int $excludeTransactionId = null): void
    {
        if ($amount <= 0) {
            abort(422, 'المبلغ يجب أن يكون أكبر من صفر');
        }

        $remaining = (float) $convention->amount - $this->spent($convention->id, $excludeTransactionId);

        if ($amount > $remaining) {
            abort(422, "المبلغ يتجاوز الرصيد المتبقي للعهدة. المتبقي: {$remaining}");
        }
    }

    // ── Low-balance notification state machine ───────────────────────────────────

    /**
     * Low-balance alert state machine.
     *
     * @param bool     $canTrigger       only a manager-performed withdrawal may raise the alert
     * @param int|null $actingManagerId  the manager who performed the withdrawal (for the message)
     */
    private function syncLowBalanceNotification(Convention $convention, bool $canTrigger = false, ?int $actingManagerId = null): void
    {
        $remaining = $this->remaining($convention);

        if ($remaining <= self::LOW_BALANCE_THRESHOLD && ! $convention->low_balance_notified) {
            // dedup + manager-only: do not send for admin actions, and never twice per cycle
            if (! $canTrigger) {
                return;
            }

            $convention->loadMissing('shop', 'shop.manager');

            $manager      = $actingManagerId ? \App\Models\User::find($actingManagerId) : $convention->shop?->manager;
            $managerName  = $manager?->name ?? 'غير محدد';
            $branchName   = $convention->shop?->name ?? 'غير محدد';
            $remainingFmt = number_format($remaining, 2);

            $this->notificationService->notifyAdmins(
                'convention_low_balance',
                'Convention Balance is Running Low',
                "The convention assigned to {$managerName} at {$branchName} has reached {$remainingFmt} EGP.\n\nPlease recharge the convention.",
                [
                    'convention_id'     => $convention->id,
                    'branch_id'         => $convention->shop_id,
                    'branch_name'       => $branchName,
                    'manager_id'        => $manager?->id,
                    'manager_name'      => $managerName,
                    'remaining_balance' => $remainingFmt,
                    'datetime'          => now()->toDateTimeString(),
                ]
            );

            $convention->update(['low_balance_notified' => true]);
        } elseif ($remaining > self::LOW_BALANCE_THRESHOLD && $convention->low_balance_notified) {
            // balance recovered (e.g. admin raised the amount) → allow a future alert
            $convention->update(['low_balance_notified' => false]);
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────────────

    private function baseConventionQuery()
    {
        return Convention::query()
            ->with(['admin:id,name,email,role', 'shop:id,name,manager_id', 'shop.manager:id,name'])
            ->withCount(['transactions as transactions_count' => fn($q) => $q->where('type', ConventionTransaction::TYPE_WITHDRAW)])
            ->withSum(['transactions as transactions_sum_amount' => fn($q) => $q->where('type', ConventionTransaction::TYPE_WITHDRAW)], 'amount');
    }
}
