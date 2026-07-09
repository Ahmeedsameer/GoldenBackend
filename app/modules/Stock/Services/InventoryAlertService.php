<?php

namespace App\Modules\Stock\Services;

use App\Models\Goods;
use App\Models\InventoryAlertState;
use App\Models\Product;
use App\Models\User;
use App\Modules\Convention\Services\NotificationService;

/**
 * Centralised inventory alerting (Section #12).
 *
 * evaluate() is called after every stock change (sale, supply, transfer,
 * adjustment). It computes the product's current stock in a location, derives
 * a level from the product's per-product thresholds, and — only when the level
 * actually changes — notifies the Admins and the branch Manager. The last
 * notified level is stored per (product, shop) so the same state never fires
 * twice.
 *
 * Levels: ok | warning | critical | out
 */
class InventoryAlertService
{
    public function __construct(private NotificationService $notifications) {}

    /** Unit labels are just the product's scalar (g, pcs, …). */
    public function evaluate(int $productId, ?int $shopId): void
    {
        // Alerts are branch-scoped. The main warehouse (shop_id = null) is a
        // staging area, not a selling location, so its levels don't alert.
        if ($shopId === null) {
            return;
        }

        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $qty   = $this->currentStock($productId, $shopId);
        $level = $this->levelFor($product, $qty);

        $state = InventoryAlertState::firstOrNew([
            'product_id' => $productId,
            'shop_id'    => $shopId,
        ]);

        // Nothing changed → no notification (de-dup for the same state).
        if ($state->exists && $state->level === $level) {
            return;
        }

        $previous     = $state->level ?? 'ok';
        $state->level = $level;

        // Only notify when entering an alerting level (warning/critical/out).
        // Recovering to a higher level (e.g. back to ok) is recorded silently so
        // a future drop will alert again.
        if (in_array($level, ['warning', 'critical', 'out'], true)) {
            $this->send($product, $shopId, $level, $qty);
            $state->notified_at = now();
        }

        $state->save();

        // Avoid "unused" warnings while keeping the previous level available for
        // future extension (e.g. escalation-only notifications).
        unset($previous);
    }

    /** Sum of on-hand stock for a product in a location (null shop = warehouse). */
    private function currentStock(int $productId, ?int $shopId): float
    {
        return (float) Goods::whereHas('supplyItem', fn ($q) => $q->where('product_id', $productId))
            ->where(function ($q) use ($shopId) {
                $shopId === null ? $q->whereNull('shop_id') : $q->where('shop_id', $shopId);
            })
            ->sum('current_quantity');
    }

    /** Derive the level from the product's per-product thresholds. */
    private function levelFor(Product $product, float $qty): string
    {
        $warn = $product->warning_quantity !== null ? (float) $product->warning_quantity : null;
        $crit = $product->critical_quantity !== null ? (float) $product->critical_quantity : null;

        if ($qty <= 0) {
            return 'out';
        }
        if ($crit !== null && $qty <= $crit) {
            return 'critical';
        }
        if ($warn !== null && $qty <= $warn) {
            return 'warning';
        }
        return 'ok';
    }

    /** Notify admins + the branch manager (managers only for their own shop). */
    private function send(Product $product, ?int $shopId, string $level, float $qty): void
    {
        $recipients = $this->recipients($shopId);
        if (empty($recipients)) {
            return;
        }

        $unit  = $product->scalar ?? '';
        $qtyTxt = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');

        $title = match ($level) {
            'out'      => 'نفاد المخزون',
            'critical' => 'مخزون حرِج',
            default    => 'مخزون منخفض',
        };

        $message = match ($level) {
            'out'      => "{$product->name} نفد من المخزون.",
            'critical' => "{$product->name} وصل إلى مستوى حرِج: تبقّى {$qtyTxt} {$unit} فقط.",
            default    => "{$product->name} مخزونه منخفض: تبقّى {$qtyTxt} {$unit} فقط.",
        };

        $this->notifications->notify($recipients, 'inventory_alert', $title, $message, [
            'product_id' => $product->id,
            'shop_id'    => $shopId,
            'level'      => $level,
            'quantity'   => $qty,
            'unit'       => $unit,
            // Reorder suggestion hook (Section #16) — the UI can offer a
            // "create supply" action from this payload.
            'suggested_action' => in_array($level, ['critical', 'out'], true) ? 'create_supply' : null,
        ]);
    }

    /**
     * Notify Admins + the branch Manager when an invoice sold an item below its
     * category's minimum selling price (Section #3 price-violation alerting).
     *
     * @param array $violations rows of {product_name, category_name, sell_price, minimum_sell_price}
     */
    public function notifyPriceViolation(?int $shopId, int $invoiceId, array $violations): void
    {
        $recipients = $this->recipients($shopId);
        if (empty($recipients) || empty($violations)) {
            return;
        }

        $lines = array_map(
            fn ($v) => "{$v['product_name']} بيع بسعر {$v['sell_price']} (الحد الأدنى {$v['minimum_sell_price']})",
            $violations
        );

        $this->notifications->notify(
            $recipients,
            'price_violation',
            'تنبيه: بيع بأقل من الحد الأدنى',
            "الفاتورة #{$invoiceId}: " . implode(' — ', $lines),
            [
                'invoice_id' => $invoiceId,
                'shop_id'    => $shopId,
                'violations' => $violations,
            ]
        );
    }

    /** @return int[] admin ids + manager ids for the given shop. */
    private function recipients(?int $shopId): array
    {
        $adminIds = User::where('role', 'admin')->pluck('id')->all();

        $managerIds = $shopId
            ? User::where('role', 'manager')->where('shop_id', $shopId)->pluck('id')->all()
            : [];

        return array_merge($adminIds, $managerIds);
    }
}
