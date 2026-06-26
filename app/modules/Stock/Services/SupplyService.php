<?php

namespace App\Modules\Stock\Services;

use App\Models\Goods;
use App\Models\Supply;
use App\Models\SupplyItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SupplyService
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Supply::query()
            ->with(['supplier:id,name,phone', 'items.product:id,name,scalar'])
            ->withCount('items');

        if (!empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function findOrFail(int $id): Supply
    {
        return Supply::with([
            'supplier:id,name,phone',
            'items.product:id,name,scalar',
            'items.goods',
        ])->findOrFail($id);
    }

    /**
     * إنشاء توريد مع أصنافه وإضافتها تلقائياً إلى المستودع الرئيسي.
     */
    public function create(array $data): Supply
    {
        return DB::transaction(function () use ($data) {
            // 1. إنشاء سجل التوريد
            $supply = Supply::create([
                'supplier_id'    => $data['supplier_id'],
                'date'           => $data['date'],
                'payment_method' => $data['payment_method'],
            ]);

            // 2. إنشاء كل صنف وإضافة كميته إلى المستودع الرئيسي تلقائياً
            foreach ($data['items'] as $item) {
                $supplyItem = SupplyItem::create([
                    'supply_id'  => $supply->id,
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                ]);

                // التخزين التلقائي في المستودع الرئيسي (shop_id = null)
                Goods::create([
                    'supply_item_id'   => $supplyItem->id,
                    'shop_id'          => null,
                    'current_quantity' => $item['quantity'],
                    'date'             => $data['date'],
                ]);
            }

            return $supply->load(['supplier:id,name,phone', 'items.product:id,name,scalar']);
        });
    }

    /**
     * تحديث بيانات التوريد الرئيسية فقط.
     * الأصناف لا تُعدَّل بعد الإنشاء لضمان سلامة سجلات المخزون.
     */
    public function update(Supply $supply, array $data): Supply
    {
        $supply->update($data);
        return $supply->fresh(['supplier:id,name,phone', 'items.product:id,name,scalar']);
    }

    /**
     * حذف التوريد — يُمنع إن كانت بضاعته قد نُقلت جزئياً إلى فروع.
     * الـ cascade يحذف supply_items ثم goods تلقائياً.
     */
    public function delete(Supply $supply): void
    {
        DB::transaction(function () use ($supply) {
            $hasTransferred = $supply->items()
                ->whereHas('goods', fn($q) => $q->whereNotNull('shop_id'))
                ->exists();

            if ($hasTransferred) {
                abort(422, 'لا يمكن حذف التوريد لأن جزءاً من بضاعته قد نُقل إلى الفروع');
            }

            $supply->delete();
        });
    }
}
