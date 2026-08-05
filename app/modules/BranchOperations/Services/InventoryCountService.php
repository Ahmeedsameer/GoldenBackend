<?php

namespace App\Modules\BranchOperations\Services;

use App\Models\Goods;
use App\Models\User;
use App\Modules\BranchOperations\Models\InventoryCountItem;
use App\Modules\BranchOperations\Models\InventoryCountSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Part 8 — Create Session -> Assign Employees -> Count Products -> Compare
 * With System -> Review Differences -> Approve -> Adjust Inventory.
 * The final "Adjust Inventory" step deliberately reuses
 * InventoryAdjustmentService end-to-end (request+approve+execute) instead
 * of touching Goods itself, so there is exactly one place in the codebase
 * that ever executes an inventory correction.
 */
class InventoryCountService
{
    public function __construct(private InventoryAdjustmentService $adjustments) {}

    public function create(int $shopId, array $employeeIds, User $user, ?string $notes = null): InventoryCountSession
    {
        return DB::transaction(function () use ($shopId, $employeeIds, $user, $notes) {
            $session = InventoryCountSession::create([
                'shop_id' => $shopId,
                'created_by' => $user->id,
                'status' => InventoryCountSession::STATUS_COUNTING,
                'notes' => $notes,
            ]);

            if (! empty($employeeIds)) {
                $session->employees()->sync($employeeIds);
            }

            // Snapshot every product currently stocked at this shop.
            $stock = Goods::where('shop_id', $shopId)
                ->join('supply_items', 'goods.supply_item_id', '=', 'supply_items.id')
                ->selectRaw('supply_items.product_id, SUM(goods.current_quantity) as qty')
                ->groupBy('supply_items.product_id')
                ->get();

            foreach ($stock as $row) {
                InventoryCountItem::create([
                    'inventory_count_session_id' => $session->id,
                    'product_id' => $row->product_id,
                    'system_quantity' => $row->qty,
                ]);
            }

            return $session->load('items.product', 'employees');
        });
    }

    /** @param array<int, array{item_id:int, physical_quantity:float}> $counts */
    public function recordCounts(InventoryCountSession $session, array $counts, User $user): InventoryCountSession
    {
        $this->assertStatus($session, InventoryCountSession::STATUS_COUNTING);

        foreach ($counts as $count) {
            $item = $session->items()->findOrFail($count['item_id']);
            $physical = (float) $count['physical_quantity'];
            $item->update([
                'physical_quantity' => $physical,
                'difference' => round($physical - (float) $item->system_quantity, 3),
                'counted_by' => $user->id,
            ]);
        }

        return $session->fresh(['items.product']);
    }

    public function submitForReview(InventoryCountSession $session, User $user): InventoryCountSession
    {
        $this->assertStatus($session, InventoryCountSession::STATUS_COUNTING);

        if ($session->items()->whereNull('physical_quantity')->exists()) {
            throw ValidationException::withMessages(['items' => 'يجب إدخال الكمية الفعلية لكل الأصناف قبل الإرسال للمراجعة']);
        }

        $session->update(['status' => InventoryCountSession::STATUS_REVIEW, 'reviewed_at' => now()]);

        return $session->fresh();
    }

    /** $reasonType is additive/optional — existing callers passing only $reason keep working unchanged. */
    public function setItemReason(InventoryCountSession $session, int $itemId, string $reason, ?string $reasonType = null): InventoryCountItem
    {
        $this->assertStatus($session, InventoryCountSession::STATUS_REVIEW);

        $item = $session->items()->findOrFail($itemId);
        $item->update(array_filter([
            'reason' => $reason,
            'reason_type' => $reasonType,
        ], fn ($v) => $v !== null));

        return $item;
    }

    public function approve(InventoryCountSession $session, User $user): InventoryCountSession
    {
        $this->assertStatus($session, InventoryCountSession::STATUS_REVIEW);
        $session->update(['status' => InventoryCountSession::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => $user->id]);

        return $session->fresh();
    }

    /**
     * Applies every non-zero difference via InventoryAdjustmentService
     * (request -> approve -> execute in one go, since the count session's
     * own approval step IS the human review this represents).
     */
    public function adjustInventory(InventoryCountSession $session, User $user): InventoryCountSession
    {
        $this->assertStatus($session, InventoryCountSession::STATUS_APPROVED);

        return DB::transaction(function () use ($session, $user) {
            foreach ($session->items as $item) {
                if ((float) $item->difference === 0.0) {
                    continue;
                }

                $req = $this->adjustments->request([
                    'shop_id' => $session->shop_id,
                    'product_id' => $item->product_id,
                    'after_quantity' => (float) $item->physical_quantity,
                    'reason' => 'جرد مخزون #' . $session->id . ($item->reason ? (' — ' . $item->reason) : ''),
                ], $user, $session->id);

                $req = $this->adjustments->approve($req, $user);
                $this->adjustments->execute($req, $user);
            }

            $session->update(['status' => InventoryCountSession::STATUS_COMPLETED, 'completed_at' => now()]);

            return $session->fresh(['items.product']);
        });
    }

    private function assertStatus(InventoryCountSession $session, string $expected): void
    {
        if ($session->status !== $expected) {
            abort(422, "لا يمكن تنفيذ هذا الإجراء — حالة الجلسة الحالية \"{$session->status}\"");
        }
    }
}
