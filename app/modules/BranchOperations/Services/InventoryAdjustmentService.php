<?php

namespace App\Modules\BranchOperations\Services;

use App\Models\Goods;
use App\Models\User;
use App\Modules\BranchOperations\Models\InventoryAdjustmentBatch;
use App\Modules\BranchOperations\Models\InventoryAdjustmentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Part 9 — inventory is never adjusted directly. request() snapshots the
 * current system quantity; approve()/reject() are pure paperwork; execute()
 * is the ONLY method that ever touches Goods.current_quantity, and only
 * once, from an approved request.
 */
class InventoryAdjustmentService
{
    public function currentQuantity(int $productId, int $shopId): float
    {
        return (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $productId))
            ->where('shop_id', $shopId)
            ->sum('current_quantity');
    }

    /** @param array{shop_id:int, product_id:int, after_quantity:float, reason:string} $data */
    public function request(array $data, User $user, ?int $countSessionId = null): InventoryAdjustmentRequest
    {
        $before = $this->currentQuantity((int) $data['product_id'], (int) $data['shop_id']);
        $after = (float) $data['after_quantity'];

        return InventoryAdjustmentRequest::create([
            'shop_id' => $data['shop_id'],
            'product_id' => $data['product_id'],
            'before_quantity' => $before,
            'after_quantity' => $after,
            'difference' => round($after - $before, 3),
            'reason' => $data['reason'],
            'requested_by' => $user->id,
            'status' => InventoryAdjustmentRequest::STATUS_PENDING,
            'inventory_count_session_id' => $countSessionId,
        ]);
    }

    public function approve(InventoryAdjustmentRequest $req, User $user): InventoryAdjustmentRequest
    {
        $this->assertStatus($req, InventoryAdjustmentRequest::STATUS_PENDING);
        $req->update(['status' => InventoryAdjustmentRequest::STATUS_APPROVED, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);

        return $req->fresh();
    }

    public function reject(InventoryAdjustmentRequest $req, User $user): InventoryAdjustmentRequest
    {
        $this->assertStatus($req, InventoryAdjustmentRequest::STATUS_PENDING);
        $req->update(['status' => InventoryAdjustmentRequest::STATUS_REJECTED, 'reviewed_by' => $user->id, 'reviewed_at' => now()]);

        return $req->fresh();
    }

    /**
     * Applies the approved difference to Goods. A shortfall (difference < 0)
     * is FIFO-decremented across existing batches, identical to
     * WasteService/TransferRequestService::ship. A surplus (difference > 0)
     * is credited to the most-recently-dated batch — the same "attach to
     * latest batch" fallback SalesService::processItem already uses when it
     * can't resolve a specific batch, since a manual count correction has no
     * originating purchase batch of its own.
     */
    public function execute(InventoryAdjustmentRequest $req, User $user): InventoryAdjustmentRequest
    {
        $this->assertStatus($req, InventoryAdjustmentRequest::STATUS_APPROVED);

        return DB::transaction(function () use ($req, $user) {
            $diff = (float) $req->difference;

            if ($diff < 0) {
                $remaining = abs($diff);
                $batches = Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $req->product_id))
                    ->where('shop_id', $req->shop_id)
                    ->where('current_quantity', '>', 0)
                    ->orderBy('date')->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($batches as $goods) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $take = min((float) $goods->current_quantity, $remaining);
                    $goods->decrement('current_quantity', $take);
                    InventoryAdjustmentBatch::create([
                        'inventory_adjustment_request_id' => $req->id,
                        'goods_id' => $goods->id,
                        'quantity_delta' => -$take,
                        'created_at' => now(),
                    ]);
                    $remaining = round($remaining - $take, 3);
                }

                if ($remaining > 0) {
                    throw ValidationException::withMessages(['difference' => 'تعذّر تنفيذ التسوية — الكمية المتاحة تغيّرت منذ إنشاء الطلب']);
                }
            } elseif ($diff > 0) {
                $latest = Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $req->product_id))
                    ->where('shop_id', $req->shop_id)
                    ->orderByDesc('date')->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (! $latest) {
                    throw ValidationException::withMessages(['difference' => 'لا يمكن زيادة رصيد منتج لا يملك أي دفعة شراء سابقة في هذا الفرع']);
                }

                $latest->increment('current_quantity', $diff);
                InventoryAdjustmentBatch::create([
                    'inventory_adjustment_request_id' => $req->id,
                    'goods_id' => $latest->id,
                    'quantity_delta' => $diff,
                    'created_at' => now(),
                ]);
            }

            $req->update(['status' => InventoryAdjustmentRequest::STATUS_EXECUTED, 'executed_at' => now()]);

            // Only affects the warehouse-scoped display-price cache when this
            // adjustment's shop actually IS the warehouse — a no-op otherwise.
            if ($product = \App\Models\Product::find($req->product_id)) {
                app(\App\Modules\Pricing\Services\PricingService::class)->syncDisplayPrice($product);
            }

            return $req->fresh();
        });
    }

    private function assertStatus(InventoryAdjustmentRequest $req, string $expected): void
    {
        if ($req->status !== $expected) {
            abort(422, "لا يمكن تنفيذ هذا الإجراء — حالة الطلب الحالية \"{$req->status}\"");
        }
    }
}
