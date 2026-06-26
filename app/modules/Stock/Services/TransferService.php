<?php

namespace App\Modules\Stock\Services;

use App\Models\Goods;
use Illuminate\Support\Facades\DB;

class TransferService
{
    /**
     * نقل كمية من موقع إلى آخر.
     *
     * الحالات المدعومة:
     *  - المستودع الرئيسي → فرع        (to_shop_id = رقم)
     *  - فرع               → فرع        (to_shop_id = رقم مختلف)
     *  - فرع               → مستودع     (to_shop_id = null)
     *
     * @param  Goods     $source     سجل البضاعة المصدر
     * @param  float     $quantity   الكمية المراد نقلها
     * @param  int|null  $toShopId   معرّف الفرع المستهدف (null = مستودع رئيسي)
     * @return array{source: Goods, destination: Goods}
     */
    public function transfer(Goods $source, float $quantity, ?int $toShopId): array
    {
        // ── التحقق من الكمية المتاحة ─────────────────────────────────────────
        if ((float) $source->current_quantity < $quantity) {
            abort(422, 'الكمية غير متوفرة، الرصيد الحالي: ' . $source->current_quantity);
        }

        // ── منع النقل من موقع إلى نفسه ──────────────────────────────────────
        $sameLocation = ($source->shop_id === null && $toShopId === null)
            || ($source->shop_id !== null && (int) $source->shop_id === (int) $toShopId);

        if ($sameLocation) {
            abort(422, 'لا يمكن النقل من الموقع نفسه إلى نفسه');
        }

        return DB::transaction(function () use ($source, $quantity, $toShopId) {
            // ── 1. خصم الكمية من المصدر ─────────────────────────────────────
            $source->decrement('current_quantity', $quantity);
            $source->refresh();

            // ── 2. البحث عن سجل بضاعة قائم في الوجهة لنفس الصنف ────────────
            $destination = Goods::where('supply_item_id', $source->supply_item_id)
                ->where(function ($q) use ($toShopId) {
                    $toShopId === null
                        ? $q->whereNull('shop_id')
                        : $q->where('shop_id', $toShopId);
                })
                ->lockForUpdate()
                ->first();

            if ($destination) {
                // سجل موجود: أضف الكمية إليه
                $destination->increment('current_quantity', $quantity);
                $destination->refresh();
            } else {
                // لا يوجد سجل: أنشئ سجلاً جديداً في الوجهة
                $destination = Goods::create([
                    'supply_item_id'   => $source->supply_item_id,
                    'shop_id'          => $toShopId,
                    'current_quantity' => $quantity,
                    'date'             => now()->toDateString(),
                ]);
            }

            return [
                'source'      => $source->load('supplyItem.product:id,name,scalar'),
                'destination' => $destination->load(
                    'supplyItem.product:id,name,scalar',
                    'shop:id,name'
                ),
            ];
        });
    }
}
