<?php

namespace App\Modules\BranchOperations\Services;

use App\Models\Goods;
use App\Models\Product;
use App\Models\User;
use App\Modules\BranchOperations\Models\WasteRecord;
use App\Modules\BranchOperations\Models\WasteRecordBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers inventory written off as waste — decrements the shop's Goods
 * (same FIFO ordering/locking as SalesService::processItem and
 * TransferRequestService::ship) and records an immutable WasteRecord as the
 * audit trail for that action (who, when, how much, why).
 */
class WasteService
{
    /**
     * @param array{shop_id:int, product_id:int, quantity:float, reason:string, date?:string, notes?:string} $data
     */
    public function register(array $data, User $user): WasteRecord
    {
        $product = Product::findOrFail($data['product_id']);
        $quantity = (float) $data['quantity'];

        return DB::transaction(function () use ($data, $user, $product, $quantity) {
            $batches = Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $product->id))
                ->where('shop_id', $data['shop_id'])
                ->where('current_quantity', '>', 0)
                ->orderBy('date')->orderBy('id')
                ->lockForUpdate()
                ->get();

            $remaining = $quantity;
            $consumed = [];
            foreach ($batches as $goods) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min((float) $goods->current_quantity, $remaining);
                $goods->decrement('current_quantity', $take);
                $consumed[] = ['goods_id' => $goods->id, 'quantity' => $take];
                $remaining = round($remaining - $take, 3);
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages(['quantity' => "الكمية المطلوبة إتلافها ({$quantity}) أكبر من المتاح في المخزون"]);
            }

            $estimatedValue = $quantity * (float) ($product->purchase_cost ?? 0);

            $waste = WasteRecord::create([
                'shop_id' => $data['shop_id'],
                'product_id' => $product->id,
                'quantity' => $quantity,
                'reason' => $data['reason'],
                'user_id' => $user->id,
                'date' => now()->toDateString(),
                'estimated_value' => round($estimatedValue, 2),
                'notes' => $data['notes'] ?? null,
            ]);

            // Record which FIFO batches this waste was drawn from — enables
            // Waste -> Goods -> SupplyItem -> Supply -> Supplier traceability
            // (see WasteRecordBatch) without duplicating any supplier data.
            foreach ($consumed as $batch) {
                WasteRecordBatch::create([
                    'waste_record_id' => $waste->id,
                    'goods_id' => $batch['goods_id'],
                    'quantity' => $batch['quantity'],
                    'created_at' => now(),
                ]);
            }

            // Only affects the warehouse-scoped display-price cache when this
            // waste's shop actually IS the warehouse — a no-op otherwise.
            app(\App\Modules\Pricing\Services\PricingService::class)->syncDisplayPrice($product);

            return $waste;
        });
    }
}
