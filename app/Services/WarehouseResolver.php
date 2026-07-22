<?php

namespace App\Services;

use App\Models\Shop;

/**
 * Phase 5.1/5.2 — the Main Warehouse is a real Shop row (so Transfers,
 * Reports, Dashboards, Notifications and Internal Invoices need zero
 * special-casing), but physical stock still lives on Goods rows with
 * shop_id = NULL (the convention already used everywhere — see
 * Goods.php, StockMovementService.php). This is the ONLY place that
 * bridges the two: the inventory layer (wherever it queries/writes
 * Goods.shop_id using a shop id that might be the warehouse) calls
 * goodsShopId() to translate the warehouse Shop's id into NULL.
 *
 * Nothing outside the inventory layer should ever need this class —
 * everywhere else (transfer requests, reports, dashboards, invoices)
 * just uses the warehouse's real Shop id like any other branch.
 */
class WarehouseResolver
{
    private static ?int $warehouseShopId = null;
    private static bool $resolved = false;

    public function warehouseShopId(): ?int
    {
        if (! self::$resolved) {
            self::$warehouseShopId = Shop::where('is_warehouse', true)->value('id');
            self::$resolved = true;
        }

        return self::$warehouseShopId;
    }

    public function isWarehouse(?int $shopId): bool
    {
        return $shopId !== null && $shopId === $this->warehouseShopId();
    }

    /** Translate a shop id into the value Goods.shop_id actually uses: NULL for the warehouse, unchanged otherwise. */
    public function goodsShopId(?int $shopId): ?int
    {
        return $this->isWarehouse($shopId) ? null : $shopId;
    }
}
